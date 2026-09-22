<script setup>
import { computed } from 'vue'
import { Cat, Dog, UserRound } from '@lucide/vue'
import StatusPill from '@/components/base/StatusPill.vue'
import { descreverAnimal } from '@/lib/animais.js'

/**
 * Cartão do animal (§5.2 do briefing), usado no painel (T01) e na relação de
 * animais (T02). Toda a área é clicável, e nenhuma ação destrutiva mora aqui —
 * o tutor não exclui animal.
 */
const props = defineProps({
  animal: { type: Object, required: true },
})

const icone = computed(() => (props.animal.especie === 'gato' ? Cat : Dog))
const descricao = computed(() => descreverAnimal(props.animal))
</script>

<template>
  <RouterLink :to="`/animais/${animal.codigo}`" class="animal-card">
    <!-- RN17 — a condição preliminar é visível em toda tela que exiba o animal,
         e diz de quem é a pendência: do veterinário, não do tutor. -->
    <span v-if="animal.preliminar" class="animal-card__tarja">
      <UserRound :size="14" :stroke-width="1.75" />
      Cadastro preliminar: aguarda caracterização por veterinário
    </span>

    <span class="animal-card__corpo">
      <span class="animal-card__foto">
        <img v-if="animal.foto_url" :src="animal.foto_url" :alt="`Foto de ${animal.nome}`" />
        <component :is="icone" v-else :size="24" :stroke-width="1.75" />
      </span>

      <span class="animal-card__dados">
        <span class="animal-card__nome">{{ animal.nome }}</span>
        <span class="animal-card__meta">{{ descricao }}</span>
      </span>

      <StatusPill
        v-if="animal.situacao"
        :tipo="animal.situacao.tipo"
        :texto="animal.situacao.texto"
        class="animal-card__situacao"
      />
    </span>
  </RouterLink>
</template>

<style scoped>
.animal-card {
  display: block;
  overflow: hidden;
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
  color: var(--ink);
}

.animal-card:hover {
  border-color: var(--border-strong);
  color: var(--ink);
}

.animal-card__tarja {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  background: var(--consent-wash);
  color: var(--consent);
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
}

.animal-card__corpo {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  min-height: 88px;
  padding: var(--space-4);
}

.animal-card__foto {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 56px;
  height: 56px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-sunken);
  color: var(--ink-muted);
}

.animal-card__foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.animal-card__dados {
  flex: 1;
  min-width: 0;
}

.animal-card__nome {
  display: block;
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
}

.animal-card__meta {
  display: block;
  font-size: 16px;
  line-height: 24px;
  color: var(--ink-muted);
}

.animal-card__situacao {
  flex: none;
}
</style>
