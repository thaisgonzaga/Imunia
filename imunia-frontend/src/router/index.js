import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/public/HomeView.vue'),
    },
    {
      path: '/entrar',
      name: 'login',
      component: () => import('@/views/public/LoginView.vue'),
    },
    {
      path: '/cadastrar-prestador',
      name: 'register-provider',
      component: () => import('@/views/public/RegisterProviderView.vue'),
    },
    {
      path: '/criar-conta',
      name: 'create-tutor-account',
      component: () => import('@/views/public/CreateTutorAccountView.vue'),
    },
    {
      path: '/recuperar-senha',
      name: 'forgot-password',
      component: () => import('@/views/public/ForgotPasswordView.vue'),
    },
    {
      path: '/redefinir-senha/:token',
      name: 'reset-password',
      component: () => import('@/views/public/ResetPasswordView.vue'),
    },
    {
      path: '/convite/:token',
      name: 'accept-invite',
      component: () => import('@/views/public/AcceptInviteView.vue'),
    },
    {
      // Sem código, a tela pede o do rodapé; com código — o caminho do QR Code
      // impresso —, verifica sozinha (RF47).
      path: '/verificar/:codigo?',
      name: 'verify-document',
      component: () => import('@/views/public/VerifyDocumentView.vue'),
    },
    {
      // Sem token, a tela é a da espera pela confirmação; com token, confirma.
      path: '/verificar-email/:token?',
      name: 'verify-email',
      component: () => import('@/views/public/VerifyEmailView.vue'),
    },
    {
      // E02 — cobre também os painéis ainda não construídos, para os quais a
      // autenticação já sabe encaminhar.
      path: '/:caminho(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
    },
  ],
})

export default router
