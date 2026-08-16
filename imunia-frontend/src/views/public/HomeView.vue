<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  Building2,
  CalendarClock,
  CircleCheck,
  ClockAlert,
  FileCheck,
  KeyRound,
  Lock,
  PawPrint,
  UserRoundCheck,
} from '@lucide/vue'
import PublicHeader from '@/components/public/PublicHeader.vue'
import PublicFooter from '@/components/public/PublicFooter.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import {
  codigoDocumentoValido,
  formatarCodigoDocumento,
  normalizarCodigoDocumento,
} from '@/lib/documento.js'

const router = useRouter()

/**
 * O trilho do herói é o produto em funcionamento, não ilustração: mesmas
 * estações, mesmos estados e mesma tipografia das telas internas. Por isso as
 * datas do exemplo são derivadas de hoje — um reforço "em 6 dias" com data já
 * vencida desmentiria a própria demonstração.
 */
const DIAS_ATE_O_REFORCO = 6

function emDias(dias) {
  const data = new Date()
  data.setDate(data.getDate() + dias)
  return data
}

function porExtenso(data) {
  return new Intl.DateTimeFormat('pt-BR').format(data)
}

const reforco = emDias(DIAS_ATE_O_REFORCO)
const segundaDose = emDias(DIAS_ATE_O_REFORCO - 365)
const primeiraDose = emDias(DIAS_ATE_O_REFORCO - 386)

const estacoes = [
  {
    rotulo: '1ª dose',
    detalhe: `${porExtenso(primeiraDose)} · lote A2F-4471`,
    situacao: 'aplicada',
  },
  {
    rotulo: '2ª dose',
    detalhe: `${porExtenso(segundaDose)} · lote A2F-4471`,
    situacao: 'aplicada',
  },
  {
    rotulo: 'Reforço anual',
    detalhe: `em ${DIAS_ATE_O_REFORCO} dias · ${porExtenso(reforco)}`,
    situacao: 'a-vencer',
  },
]

const diferenciais = [
  {
    icone: CalendarClock,
    tom: 'marca',
    titulo: 'Calendário calculado',
    texto:
      'O sistema calcula a próxima dose a partir do protocolo vigente, da espécie e da idade do animal. O tutor recebe o lembrete antes do vencimento, e não depois.',
  },
  {
    icone: FileCheck,
    tom: 'marca',
    titulo: 'Carteira verificável',
    texto:
      'Cada aplicação registra fabricante, lote, validade e o CRMV de quem aplicou. O PDF gerado pode ser conferido por qualquer pessoa, sem conta no Imunia.',
  },
  {
    icone: KeyRound,
    tom: 'consentimento',
    titulo: 'Autorização do tutor',
    texto:
      'Nenhuma clínica vê o histórico sem autorização, e toda consulta fica registrada para o tutor. A autorização é revogável a qualquer momento, sem justificativa.',
  },
]

const codigo = ref('')
const erroCodigo = ref('')

const codigoFormatado = computed({
  get: () => codigo.value,
  set: (valor) => {
    codigo.value = formatarCodigoDocumento(valor)
    if (erroCodigo.value) erroCodigo.value = ''
  },
})

function verificar() {
  if (!codigoDocumentoValido(codigo.value)) {
    erroCodigo.value = 'O código tem 16 caracteres, como no rodapé do PDF.'
    return
  }

  // A consulta em si é de P09: aqui só se confere o formato, para não gastar
  // uma tentativa da rota pública com erro de digitação (RF47c).
  router.push(`/verificar/${normalizarCodigoDocumento(codigo.value)}`)
}
</script>

<template>
  <div class="home">
    <PublicHeader />

    <main>
      <section class="home-hero">
        <div class="home-hero__inner">
          <div class="home-hero__pitch">
            <h1 class="home-hero__title">
              O histórico do seu animal pertence a ele, não à clínica.
            </h1>
            <p class="home-hero__lead">
              O Imunia guarda a carteira de vacinação e o prontuário de cães e gatos em um
              registro que acompanha o animal por toda a vida, mesmo que ele troque de
              clínica. Quem decide quem pode ver esse histórico é o tutor.
            </p>

            <div class="home-hero__actions">
              <RouterLink to="/criar-conta" class="home-cta home-cta--primary">
                <PawPrint :size="20" />
                Criar conta de tutor
              </RouterLink>
              <RouterLink to="/cadastrar-prestador" class="home-cta home-cta--secondary">
                <Building2 :size="20" class="home-cta__icon" />
                Cadastrar meu estabelecimento
              </RouterLink>
            </div>

            <p class="home-hero__assurance">
              <Lock :size="16" />
              Registro clínico imutável, com responsabilidade técnica identificada em cada
              aplicação.
            </p>
          </div>

          <!-- Demonstração silenciosa: a mesma peça que o tutor vê em T05. -->
          <aside class="home-rail" aria-label="Exemplo de trilho vacinal">
            <div class="home-rail__head">
              <span class="home-rail__vaccine">V10 múltipla canina</span>
              <span class="home-rail__tag">exemplo</span>
            </div>

            <ol class="home-rail__list">
              <li
                v-for="(estacao, indice) in estacoes"
                :key="estacao.rotulo"
                class="home-rail__station"
                :style="{ '--atraso': `${indice * 60}ms` }"
              >
                <span class="home-rail__mark" :class="`home-rail__mark--${estacao.situacao}`">
                  <component
                    :is="estacao.situacao === 'aplicada' ? CircleCheck : ClockAlert"
                    :size="estacao.situacao === 'aplicada' ? 16 : 14"
                  />
                </span>
                <span class="home-rail__body">
                  <span class="home-rail__label">{{ estacao.rotulo }}</span>
                  <span
                    class="home-rail__detail"
                    :class="{ 'home-rail__detail--a-vencer': estacao.situacao === 'a-vencer' }"
                  >{{ estacao.detalhe }}</span>
                </span>
              </li>
            </ol>

            <div class="home-rail__foot">
              <span class="home-rail__seal">
                <UserRoundCheck :size="16" class="home-rail__seal-icon" />
                <span>Cada dose traz clínica, profissional e CRMV</span>
              </span>
            </div>
          </aside>
        </div>
      </section>

      <section class="home-pillars">
        <div class="home-pillars__inner">
          <article v-for="pilar in diferenciais" :key="pilar.titulo" class="home-pillar">
            <component :is="pilar.icone" :size="24" :class="`home-pillar__icon--${pilar.tom}`" />
            <h2 class="home-pillar__title">{{ pilar.titulo }}</h2>
            <p class="home-pillar__text">{{ pilar.texto }}</p>
          </article>
        </div>
      </section>

      <section class="home-verify">
        <div class="home-verify__inner">
          <div>
            <p class="home-verify__eyebrow">
              <FileCheck :size="16" />
              Verificação pública
            </p>
            <h2 class="home-verify__title">Recebeu um documento do Imunia? Verifique aqui</h2>
            <p class="home-verify__text">
              Digite o código impresso no rodapé do PDF. Você não precisa de conta, e a
              verificação não expõe dado clínico nem dado do tutor.
            </p>
          </div>

          <form class="home-verify__form" @submit.prevent="verificar">
            <AppInput
              id="codigo-documento"
              label="Código do documento"
              placeholder="9F2C 4A81 D7E0 5B33"
              autocomplete="off"
              mono
              v-model="codigoFormatado"
              :error="erroCodigo"
            />
            <AppButton class="home-verify__submit" type="submit" variant="consentimento">
              Verificar
            </AppButton>
          </form>
        </div>
      </section>
    </main>

    <PublicFooter />
  </div>
</template>

<style scoped>
.home {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: var(--surface-page);
}

/* Herói */

.home-hero {
  display: flex;
  justify-content: center;
  padding: var(--space-8) var(--space-4);
  background: var(--surface-card);
  border-bottom: 1px solid var(--border-hairline);
}

.home-hero__inner {
  display: grid;
  gap: var(--space-8);
  width: 100%;
  max-width: 1280px;
}

.home-hero__title {
  margin: 0;
  max-width: 24ch;
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 34px;
  font-weight: 600;
  letter-spacing: -.02em;
  color: var(--ink);
  text-wrap: pretty;
}

.home-hero__lead {
  margin: var(--space-4) 0 0;
  max-width: 60ch;
  color: var(--ink-muted);
  text-wrap: pretty;
}

.home-hero__actions {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
}

.home-cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  height: 48px;
  padding: 0 var(--space-6);
  border-radius: var(--radius-sm);
  font-size: 16px;
  font-weight: 600;
  transition: background-color 120ms cubic-bezier(.2, 0, 0, 1), border-color 120ms cubic-bezier(.2, 0, 0, 1);
}

.home-cta svg {
  flex: none;
}

.home-cta--primary {
  background: var(--brand);
  border: 1px solid var(--brand);
  color: var(--surface-card);
}

.home-cta--primary:hover {
  background: var(--brand-hover);
  border-color: var(--brand-hover);
  color: var(--surface-card);
}

.home-cta--secondary {
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  color: var(--ink);
}

.home-cta--secondary:hover {
  background: var(--surface-sunken);
  color: var(--ink);
}

.home-cta__icon {
  color: var(--ink-muted);
}

.home-hero__assurance {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  margin: var(--space-8) 0 0;
  font-size: 14px;
  line-height: 20px;
  color: var(--ink-muted);
}

.home-hero__assurance svg {
  flex: none;
  margin-top: 2px;
}

/* Trilho de exemplo */

.home-rail {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.home-rail__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
}

.home-rail__vaccine {
  font-family: var(--font-display);
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.home-rail__tag {
  font-size: 12px;
  line-height: 16px;
  color: var(--ink-faint);
}

.home-rail__list {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin: var(--space-4) 0 0;
  padding: 0 0 0 var(--space-1);
  list-style: none;
}

/* Linha que costura as estações; fica atrás dos marcadores, que a interrompem
   com o halo da própria superfície. */
.home-rail__list::before {
  content: '';
  position: absolute;
  left: 15px;
  top: 12px;
  bottom: 12px;
  width: 2px;
  background: var(--border-strong);
}

.home-rail__station {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  /* Carga única, 200 ms, como manda o verbete de P01. */
  animation: home-rail-entrada 200ms cubic-bezier(.2, 0, 0, 1) both;
  animation-delay: var(--atraso);
}

.home-rail__mark {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 24px;
  height: 24px;
  border-radius: var(--radius-pill);
  box-shadow: 0 0 0 4px var(--surface-card);
}

.home-rail__mark--aplicada {
  background: var(--status-ok);
  color: var(--surface-card);
}

.home-rail__mark--a-vencer {
  background: var(--surface-card);
  border: 2px solid var(--status-due);
  color: var(--status-due-text);
}

.home-rail__body {
  display: flex;
  flex-direction: column;
}

.home-rail__label {
  font-weight: 600;
  color: var(--ink);
}

.home-rail__detail {
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 18px;
  color: var(--ink-muted);
  font-variant-numeric: tabular-nums;
}

.home-rail__detail--a-vencer {
  font-weight: 500;
  color: var(--status-due-text);
}

.home-rail__foot {
  margin: var(--space-4) 0 0;
  padding: var(--space-3);
  background: var(--surface-page);
  border-radius: var(--radius-sm);
}

.home-rail__seal {
  display: inline-flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: 6px 10px;
  background: var(--surface-card);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-xs);
  font-size: 12px;
  line-height: 16px;
  color: var(--ink);
}

.home-rail__seal-icon {
  flex: none;
  color: var(--brand);
}

@keyframes home-rail-entrada {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: none; }
}

/* Três diferenciais */

.home-pillars {
  display: flex;
  justify-content: center;
  padding: var(--space-4);
  background: var(--surface-page);
}

.home-pillars__inner {
  display: grid;
  gap: var(--space-4);
  width: 100%;
  max-width: 1280px;
}

.home-pillar {
  padding: var(--space-4);
  background: var(--surface-card);
  border: 1px solid var(--border-hairline);
  border-radius: var(--radius-md);
}

.home-pillar__icon--marca {
  color: var(--brand);
}

.home-pillar__icon--consentimento {
  color: var(--consent);
}

.home-pillar__title {
  margin: var(--space-3) 0 0;
  font-family: var(--font-body);
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--ink);
}

.home-pillar__text {
  margin: var(--space-2) 0 0;
  color: var(--ink-muted);
  text-wrap: pretty;
}

/* Faixa de verificação pública */

.home-verify {
  display: flex;
  justify-content: center;
  padding: var(--space-6) var(--space-4);
  background: var(--consent-wash);
  border-top: 1px solid var(--consent);
}

.home-verify__inner {
  display: grid;
  gap: var(--space-6);
  width: 100%;
  max-width: 1280px;
  align-items: center;
}

.home-verify__eyebrow {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
  font-size: 13px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: .06em;
  text-transform: uppercase;
  color: var(--consent);
}

.home-verify__eyebrow svg {
  flex: none;
}

.home-verify__title {
  margin: var(--space-2) 0 0;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  color: var(--ink);
}

.home-verify__text {
  margin: var(--space-2) 0 0;
  max-width: 60ch;
  color: var(--ink-muted);
}

.home-verify__form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

/* Na faixa de consentimento o campo veste a cor do bloco, e não a do sistema. */
.home-verify__form :deep(.app-field__label) {
  color: var(--consent);
}

.home-verify__form :deep(.app-field__input:not(.app-field__input--error)) {
  border-color: var(--consent);
}

@media (min-width: 768px) {
  .home-hero {
    padding: var(--space-16) var(--space-12);
  }

  .home-hero__title {
    font-size: 36px;
    line-height: 40px;
  }

  .home-hero__lead {
    margin-top: var(--space-6);
  }

  .home-hero__actions {
    flex-direction: row;
    gap: var(--space-3);
    margin-top: var(--space-8);
  }

  .home-cta {
    justify-content: flex-start;
  }

  .home-pillars {
    padding: var(--space-16) var(--space-12);
  }

  .home-pillars__inner {
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-8);
  }

  /* Acima de 768 px os três blocos formam a própria faixa: o cartão em volta
     de cada um vira ruído. */
  .home-pillar {
    padding: 0;
    background: none;
    border: none;
  }

  .home-pillar__title {
    margin-top: var(--space-4);
  }

  .home-verify {
    padding: var(--space-12);
  }

  .home-verify__form {
    flex-direction: row;
    align-items: flex-end;
  }

  .home-verify__form :deep(.app-field) {
    flex: 1;
    min-width: 0;
  }

  .home-verify__submit {
    flex: none;
  }
}

@media (min-width: 1024px) {
  .home-hero__inner {
    grid-template-columns: 1fr 420px;
    gap: var(--space-16);
    align-items: center;
  }

  .home-rail {
    padding: var(--space-8) var(--space-6);
    background: var(--surface-page);
  }

  .home-rail__vaccine {
    font-size: 22px;
    line-height: 28px;
  }

  .home-rail__list {
    gap: 28px;
    margin-top: var(--space-6);
  }

  .home-rail__mark {
    box-shadow: 0 0 0 4px var(--surface-page);
  }

  .home-rail__foot {
    margin-top: var(--space-6);
    background: var(--surface-card);
    border: 1px solid var(--border-hairline);
  }

  .home-verify__inner {
    grid-template-columns: 1fr 480px;
    gap: var(--space-12);
  }

  .home-verify__title {
    font-size: 28px;
    line-height: 34px;
  }
}
</style>
