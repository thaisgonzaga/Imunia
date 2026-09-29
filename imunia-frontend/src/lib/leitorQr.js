import { CODIGO, classificarTermo } from '@/lib/busca.js'

/**
 * O que um QR Code lido pela câmera significa para o ambiente clínico (V03).
 *
 * Dois QR Codes circulam com a marca do Imunia. O do perfil do animal (T04)
 * traz o código único (RF17) em texto puro, para que outro aparelho o leia; o
 * do rodapé do PDF exportado (T15) aponta para a rota pública de verificação
 * (RF47). O leitor existe para o primeiro. O segundo é reconhecido para que a
 * tela diga o que leu — "isto é um documento, não um animal" — em vez de
 * tratar como ilegível um código que o próprio sistema emitiu.
 *
 * Quem decide o que a busca encontra continua sendo o servidor: daqui sai um
 * termo, e ele segue pelo mesmo caminho do código digitado — com o mesmo
 * âmbito (RN48) e o mesmo registro de acesso (RF18b).
 */
export const ANIMAL = 'animal'
export const DOCUMENTO = 'documento'
export const DESCONHECIDO = 'desconhecido'

/**
 * Um endereço que carregue o código ainda é o animal — um QR Code futuro que
 * aponte para o perfil, por exemplo. Só a forma com hifens: sem eles, dez
 * letras começadas por "IM" no meio de um texto qualquer seriam uma palavra.
 */
const CODIGO_EMBUTIDO = /\bIM-([0-9A-Z]{4})-([0-9A-Z]{4})\b/i

/** A rota pública de verificação, como `ExportacaoDeHistoricoService` a imprime. */
const DOCUMENTO_EXPORTADO = /\/verificar\/([0-9A-F]{16})\b/i

export function interpretarQr(conteudo) {
  const texto = (conteudo ?? '').trim()
  if (texto === '') return { tipo: DESCONHECIDO }

  const termo = classificarTermo(texto)
  if (termo.tipo === CODIGO) return { tipo: ANIMAL, codigo: termo.valor }

  const documento = DOCUMENTO_EXPORTADO.exec(texto)
  if (documento) return { tipo: DOCUMENTO, codigo: documento[1].toUpperCase() }

  const embutido = CODIGO_EMBUTIDO.exec(texto)
  if (embutido) {
    return { tipo: ANIMAL, codigo: `IM-${embutido[1]}-${embutido[2]}`.toUpperCase() }
  }

  return { tipo: DESCONHECIDO }
}

const ACESSO_NEGADO =
  'O acesso à câmera foi negado. Libere-o nas permissões do navegador, ou digite o código.'
const SEM_CAMERA = 'Nenhuma câmera foi encontrada neste aparelho.'
const CAMERA_OCUPADA =
  'A câmera não respondeu. Outro aplicativo pode estar usando-a — feche-o e tente de novo.'

/**
 * Por que a câmera não abriu, dito a quem vai decidir o que fazer a seguir. Os
 * nomes são os que `getUserMedia` rejeita, com as variantes antigas que alguns
 * navegadores de celular ainda usam.
 */
const FALHAS = {
  NotAllowedError: ACESSO_NEGADO,
  PermissionDeniedError: ACESSO_NEGADO,
  SecurityError: ACESSO_NEGADO,
  NotFoundError: SEM_CAMERA,
  DevicesNotFoundError: SEM_CAMERA,
  OverconstrainedError: SEM_CAMERA,
  NotReadableError: CAMERA_OCUPADA,
  TrackStartError: CAMERA_OCUPADA,
  AbortError: CAMERA_OCUPADA,
}

export function descreverFalhaDaCamera(erro, { suportada = true, seguro = true } = {}) {
  if (!suportada) {
    // `getUserMedia` só existe em contexto seguro. É o caso do dev server
    // acessado pelo celular por IP da rede local, e a pessoa precisa saber que
    // o problema é o endereço, não o aparelho.
    return seguro
      ? 'Este navegador não dá acesso à câmera.'
      : 'A câmera só abre em endereço seguro (https), e este não é.'
  }

  return FALHAS[erro?.name] ?? 'Não conseguimos abrir a câmera.'
}
