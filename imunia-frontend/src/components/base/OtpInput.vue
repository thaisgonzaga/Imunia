<script setup>
import { computed, nextTick, ref, watch } from 'vue'

/**
 * `OtpInput` (§5.2 do briefing) — as casas do código de uso único.
 *
 * Seis campos de uma casa, e não um campo de seis, porque o código chega por
 * e-mail e é digitado de memória curta, olhando para a mensagem: as casas
 * separadas dizem quanto falta sem que o tutor precise contar. O preço disso é
 * o teclado — avançar, apagar, colar — e é ele que este componente resolve, no
 * lugar de cada tela resolver de novo.
 *
 * Nada aqui guarda o código além do que está em tela: o valor sobe pelo
 * `v-model` e desce de volta, e a tela que o usa o descarta assim que o envia.
 */
const props = defineProps({
  modelValue: { type: String, default: '' },
  casas: { type: Number, default: 6 },
  id: { type: String, required: true },
  rotulo: { type: String, required: true },

  // O código não confere: as casas ficam com a borda de erro, e o texto que
  // explica o que houve fica com quem chamou — é ele que sabe quantas
  // tentativas restam.
  invalido: { type: Boolean, default: false },

  // Prazo vencido ou concessão em curso: as casas continuam à vista, apagadas,
  // porque sumir com elas faria a tela parecer outra.
  desabilitado: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'completo'])

const campos = ref([])

const digitos = computed(() => {
  const valor = props.modelValue.slice(0, props.casas)

  return Array.from({ length: props.casas }, (_, casa) => valor[casa] ?? '')
})

function escrever(casa, valor) {
  // Só número entra. O tutor que digita letra não vê nada acontecer, que é
  // resposta melhor do que aceitar e recusar depois.
  const limpo = valor.replace(/\D/g, '')

  if (limpo === '') {
    trocar(casa, '')

    return
  }

  // Colar o código inteiro numa casa distribui pelas seguintes: é o que
  // acontece quando o tutor copia do e-mail, e é o caminho mais comum.
  const atual = digitos.value.slice()

  limpo.split('').forEach((digito, deslocamento) => {
    if (casa + deslocamento < props.casas) atual[casa + deslocamento] = digito
  })

  publicar(atual)

  const proxima = Math.min(casa + limpo.length, props.casas - 1)
  focar(proxima)
}

function trocar(casa, digito) {
  const atual = digitos.value.slice()
  atual[casa] = digito

  publicar(atual)
}

function publicar(casas) {
  const valor = casas.join('').replace(/\s/g, '')

  emit('update:modelValue', valor)

  if (valor.length === props.casas) emit('completo', valor)
}

function apagar(casa, evento) {
  if (digitos.value[casa] !== '') {
    trocar(casa, '')

    return
  }

  // Casa já vazia: o retrocesso apaga a anterior e leva o cursor com ele.
  if (casa > 0) {
    evento.preventDefault()
    trocar(casa - 1, '')
    focar(casa - 1)
  }
}

function navegar(casa, passo, evento) {
  const destino = casa + passo

  if (destino < 0 || destino >= props.casas) return

  evento.preventDefault()
  focar(destino)
}

async function focar(casa) {
  await nextTick()
  campos.value[casa]?.focus()
  campos.value[casa]?.select()
}

/** Depois de um código recusado, o campo volta ao começo pronto para o novo. */
watch(
  () => props.invalido,
  (invalido) => {
    if (invalido) focar(0)
  },
)

defineExpose({ focar: () => focar(0) })
</script>

<template>
  <div
    class="otp"
    :class="{ 'otp--invalido': invalido, 'otp--desabilitado': desabilitado }"
    role="group"
    :aria-labelledby="`${id}-rotulo`"
  >
    <span :id="`${id}-rotulo`" class="visually-hidden">{{ rotulo }}</span>
    <input
      v-for="(digito, casa) in digitos"
      :key="casa"
      :ref="(elemento) => (campos[casa] = elemento)"
      :id="casa === 0 ? id : undefined"
      class="otp__casa"
      type="text"
      inputmode="numeric"
      autocomplete="one-time-code"
      :maxlength="casas"
      :value="digito"
      :disabled="desabilitado"
      :aria-label="`Casa ${casa + 1} de ${casas}`"
      :aria-invalid="invalido"
      @input="escrever(casa, $event.target.value)"
      @keydown.delete="apagar(casa, $event)"
      @keydown.left="navegar(casa, -1, $event)"
      @keydown.right="navegar(casa, 1, $event)"
      @focus="$event.target.select()"
    >
  </div>
</template>

<style scoped>
.otp {
  display: flex;
  gap: var(--space-2);
}

.otp__casa {
  /* 44 px de alvo, como o briefing exige abaixo de 1024 px (§4.3). */
  width: 44px;
  height: 48px;
  padding: 0;
  text-align: center;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  font-family: var(--font-mono);
  font-size: 18px;
  font-weight: 500;
  color: var(--ink);
}

.otp__casa:focus-visible {
  border-color: var(--consent);
  outline: 2px solid var(--brand-bright);
  outline-offset: 2px;
}

.otp--invalido .otp__casa {
  border-color: var(--status-late);
}

.otp--desabilitado {
  opacity: .45;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}
</style>
