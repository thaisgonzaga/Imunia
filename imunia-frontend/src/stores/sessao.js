import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiDelete, apiGet } from '@/lib/api.js'

/**
 * Sessão corrente do SPA. O cookie httpOnly é a única prova de autenticação —
 * o navegador não o lê, e por isso a pergunta "estou autenticada?" é sempre uma
 * consulta ao servidor (`GET /api/sessao`), nunca uma leitura de armazenamento
 * local. O resultado fica em memória para que a resposta não se repita a cada
 * navegação.
 */
export const useSessaoStore = defineStore('sessao', () => {
  const usuario = ref(null)
  const consultada = ref(false)

  const autenticado = computed(() => usuario.value !== null)

  const primeiroNome = computed(() => (usuario.value?.nome ?? '').trim().split(/\s+/)[0] ?? '')

  /**
   * Iniciais para o avatar do cabeçalho: primeira letra do primeiro nome e do
   * último. "Helena Ramos" vira "HR".
   */
  const iniciais = computed(() => {
    const partes = (usuario.value?.nome ?? '').trim().split(/\s+/).filter(Boolean)
    if (partes.length === 0) return ''

    const primeira = partes[0][0]
    const ultima = partes.length > 1 ? partes[partes.length - 1][0] : ''

    return `${primeira}${ultima}`.toUpperCase()
  })

  /**
   * Falha de consulta é tratada como ausência de sessão: seja 401 por sessão
   * expirada, seja servidor fora do ar, o que a tela protegida pode fazer é o
   * mesmo — devolver quem chegou à autenticação.
   */
  async function carregar({ recarregar = false } = {}) {
    if (consultada.value && !recarregar) return usuario.value

    try {
      const resposta = await apiGet('/api/sessao')
      usuario.value = resposta.usuario
    } catch {
      usuario.value = null
    } finally {
      consultada.value = true
    }

    return usuario.value
  }

  function registrar(dados) {
    usuario.value = dados
    consultada.value = true
  }

  async function encerrar() {
    try {
      await apiDelete('/api/sessao')
    } finally {
      usuario.value = null
      consultada.value = true
    }
  }

  return { usuario, autenticado, primeiroNome, iniciais, carregar, registrar, encerrar }
})
