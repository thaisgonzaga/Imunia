export class ApiError extends Error {
  constructor(message, { status, errors, data } = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors ?? {}
    // Corpo completo da resposta: as telas desta família dependem dele para
    // saber em que estado entrar (situação da ligação, tempo restante da pausa).
    this.data = data ?? {}
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
 * cookie + cabeçalho. O cookie é emitido por /sanctum/csrf-cookie e só
 * precisa ser buscado uma vez por sessão do navegador.
 */
async function garantirTokenCsrf() {
  if (lerCookie('XSRF-TOKEN')) return

  await fetch('/sanctum/csrf-cookie', { headers: { Accept: 'application/json' } })
}

async function requisitar(caminho, { method, payload } = {}) {
  const cabecalhos = { Accept: 'application/json' }

  if (method !== 'GET') {
    await garantirTokenCsrf()

    const token = lerCookie('XSRF-TOKEN')
    if (token) cabecalhos['X-XSRF-TOKEN'] = token
    if (payload !== undefined) cabecalhos['Content-Type'] = 'application/json'
  }

  let response
  try {
    response = await fetch(caminho, {
      method,
      headers: cabecalhos,
      body: payload === undefined ? undefined : JSON.stringify(payload),
    })
  } catch {
    throw new ApiError('Não foi possível se conectar ao servidor. Verifique sua conexão e tente novamente.')
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

export function apiDelete(caminho) {
  return requisitar(caminho, { method: 'DELETE' })
}
