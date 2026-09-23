export class ApiError extends Error {
  constructor(message, { status, errors, data } = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors ?? {}
    // Corpo completo da resposta: as telas desta família dependem dele para
    // saber em que estado entrar (situação da ligação, tempo restante da pausa).
    this.data = data ?? {}
    // Número de protocolo da requisição que falhou, presente só nas respostas
    // de falha do sistema. É o que E03 exibe para a conversa com o suporte.
    this.ocorrencia = data?.ocorrencia ?? ''
  }
}

function lerCookie(nome) {
  const encontrado = document.cookie
    .split('; ')
    .find((parte) => parte.startsWith(`${nome}=`))

  return encontrado ? decodeURIComponent(encontrado.slice(nome.length + 1)) : null
}

/**
 * O Sanctum em modo SPA protege as requisições de escrita com o par
 * cookie + cabeçalho. O cookie é emitido por /sanctum/csrf-cookie e basta
 * buscá-lo uma vez por sessão do navegador — daí a saída antecipada.
 *
 * `renovar` existe porque a presença do cookie não prova que ele ainda vale:
 * o token é conferido contra a sessão do servidor, e essa sessão pode ter
 * expirado ou sido recriada com a aba aberta. Só quem levou o 419 sabe disso,
 * e é quem pede a renovação.
 */
async function garantirTokenCsrf({ renovar = false } = {}) {
  if (!renovar && lerCookie('XSRF-TOKEN')) return

  await fetch('/sanctum/csrf-cookie', { headers: { Accept: 'application/json' } })
}

async function enviar(caminho, { method, payload, renovarCsrf = false }) {
  const cabecalhos = { Accept: 'application/json' }

  if (method !== 'GET') {
    await garantirTokenCsrf({ renovar: renovarCsrf })

    const token = lerCookie('XSRF-TOKEN')
    if (token) cabecalhos['X-XSRF-TOKEN'] = token
    if (payload !== undefined) cabecalhos['Content-Type'] = 'application/json'
  }

  try {
    return await fetch(caminho, {
      method,
      headers: cabecalhos,
      body: payload === undefined ? undefined : JSON.stringify(payload),
    })
  } catch {
    throw new ApiError('Não foi possível se conectar ao servidor. Verifique sua conexão e tente novamente.')
  }
}

async function requisitar(caminho, { method, payload } = {}) {
  let response = await enviar(caminho, { method, payload })

  // 419 — o par cookie + cabeçalho deixou de valer enquanto a aba seguia
  // aberta, e a tela receberia "CSRF token mismatch.": texto em inglês, sobre
  // um cookie que ninguém digitou, em resposta a credenciais que estavam
  // certas. Repetir é seguro mesmo em POST e DELETE porque quem recusa é o
  // middleware de CSRF, antes de qualquer controlador — a primeira tentativa
  // não chegou a escrever nada.
  //
  // Em desenvolvimento isto quase não se vê: o proxy do Vite põe SPA e API na
  // mesma origem, e o `PreventRequestForgery` do Laravel 13 aceita
  // `Sec-Fetch-Site: same-origin` como prova bastante, sem sequer conferir o
  // token. Fora dali — SPA e API em origens distintas, como no que se pretende
  // publicar — o cabeçalho passa a `same-site`, que o framework não aceita por
  // padrão, e o token volta a ser a única prova. É para esse dia que isto aqui
  // existe.
  if (response.status === 419 && method !== 'GET') {
    response = await enviar(caminho, { method, payload, renovarCsrf: true })
  }

  let body = null
  try {
    body = await response.json()
  } catch {
    // resposta sem corpo JSON (ex.: erro 500 bruto)
  }

  if (!response.ok) {
    if (response.status === 422) {
      throw new ApiError(body?.message ?? 'Há campos que precisam ser corrigidos.', {
        status: 422,
        errors: body?.errors ?? {},
        data: body ?? {},
      })
    }

    // Segunda recusa de CSRF: o token acabara de ser renovado, então insistir
    // não vai adiantar. Aqui a mensagem do Laravel é trocada pela única
    // instrução que resolve para quem está na tela.
    if (response.status === 419) {
      throw new ApiError('Sua sessão expirou. Recarregue a página e tente novamente.', {
        status: 419,
        data: body ?? {},
      })
    }

    throw new ApiError(body?.message ?? 'Não conseguimos concluir a operação. Tente novamente em instantes.', {
      status: response.status,
      data: body ?? {},
    })
  }

  return body
}

export function apiGet(caminho) {
  return requisitar(caminho, { method: 'GET' })
}

export function apiPost(caminho, payload) {
  return requisitar(caminho, { method: 'POST', payload })
}

/**
 * Edição parcial: a tela manda o que ela edita, e o servidor decide o resto.
 * É PATCH, e não PUT, porque nenhuma tela do Imunia é dona da linha inteira —
 * a de T04a, por exemplo, não toca em campo algum da caracterização (RN18).
 */
export function apiPatch(caminho, payload) {
  return requisitar(caminho, { method: 'PATCH', payload })
}

export function apiDelete(caminho) {
  return requisitar(caminho, { method: 'DELETE' })
}

/**
 * Envio de arquivo com progresso e cancelamento (V08, RF32).
 *
 * Não passa por `requisitar` porque `fetch` não informa quanto do corpo já
 * subiu, e o briefing pede progresso **por arquivo**: um laudo de 4 MB numa
 * conexão de clínica leva segundos em que a única coisa pior do que esperar é
 * não saber se está subindo. `XMLHttpRequest` é o que expõe esse evento.
 *
 * Devolve `{ promessa, cancelar }`: quem chama guarda o cancelamento para o
 * botão "Cancelar envio" e trata a promessa como qualquer outra chamada da API
 * — inclusive o `ApiError` de 422, que é como a recusa por formato ou tamanho
 * chega (RN28).
 */
export function apiUpload(caminho, arquivo, { aoProgresso } = {}) {
  // O envio pode ser feito duas vezes (ver o retry de 419 adiante), e cada
  // tentativa precisa de um `XMLHttpRequest` novo — objeto já concluído não se
  // reaproveita. O cancelamento do profissional, porém, é um só: guarda-se qual
  // é a requisição em curso, e a marca para o caso de o clique cair no intervalo
  // entre uma tentativa e outra, quando não há requisição alguma para abortar.
  let requisicaoEmCurso = null
  let cancelada = false

  const tentar = ({ renovarCsrf = false } = {}) => (async () => {
    await garantirTokenCsrf({ renovar: renovarCsrf })

    const corpo = new FormData()
    corpo.append('arquivo', arquivo)

    return new Promise((resolver, rejeitar) => {
      if (cancelada) {
        rejeitar(new ApiError('Envio cancelado.', { status: 0 }))

        return
      }

      const requisicao = new XMLHttpRequest()
      requisicaoEmCurso = requisicao

      requisicao.open('POST', caminho)
      requisicao.setRequestHeader('Accept', 'application/json')

      const token = lerCookie('XSRF-TOKEN')
      if (token) requisicao.setRequestHeader('X-XSRF-TOKEN', token)

      requisicao.upload.addEventListener('progress', (evento) => {
        if (evento.lengthComputable) aoProgresso?.(evento.loaded, evento.total)
      })

      requisicao.addEventListener('load', () => {
        let corpoDaResposta = null
        try {
          corpoDaResposta = JSON.parse(requisicao.responseText)
        } catch {
          // resposta sem corpo JSON
        }

        if (requisicao.status >= 200 && requisicao.status < 300) {
          resolver(corpoDaResposta)

          return
        }

        if (requisicao.status === 422) {
          rejeitar(new ApiError(corpoDaResposta?.message ?? 'Este arquivo não pôde ser anexado.', {
            status: 422,
            errors: corpoDaResposta?.errors ?? {},
            data: corpoDaResposta ?? {},
          }))

          return
        }

        // O status é o que o retry examina; a mensagem só chega à tela se a
        // segunda tentativa também for recusada.
        if (requisicao.status === 419) {
          rejeitar(new ApiError('Sua sessão expirou. Recarregue a página e tente novamente.', {
            status: 419,
            data: corpoDaResposta ?? {},
          }))

          return
        }

        rejeitar(new ApiError(
          corpoDaResposta?.message ?? 'Não conseguimos enviar o arquivo. Tente novamente em instantes.',
          { status: requisicao.status, data: corpoDaResposta ?? {} },
        ))
      })

      requisicao.addEventListener('error', () => rejeitar(
        new ApiError('Não foi possível se conectar ao servidor. Verifique sua conexão e tente novamente.'),
      ))

      // O cancelamento é do profissional, não uma falha: quem chama distingue
      // os dois por este status, e não escreve mensagem de erro por causa dele.
      requisicao.addEventListener('abort', () => rejeitar(
        new ApiError('Envio cancelado.', { status: 0 }),
      ))

      requisicao.send(corpo)
    })
  })()

  // Mesmo 419 de `requisitar`, pelo mesmo motivo e com a mesma garantia de que
  // repetir não duplica anexo: a recusa vem do middleware de CSRF, antes de o
  // arquivo virar rascunho. O que se paga é o reenvio do corpo — a barra de
  // progresso volta a zero e sobe de novo, preferível a perder o laudo já
  // escolhido no meio de um atendimento.
  const promessa = tentar().catch((erro) => {
    if (erro instanceof ApiError && erro.status === 419 && !cancelada) {
      return tentar({ renovarCsrf: true })
    }

    throw erro
  })

  return {
    promessa,
    cancelar: () => {
      cancelada = true
      requisicaoEmCurso?.abort()
    },
  }
}
