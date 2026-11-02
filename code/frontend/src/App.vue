<script setup>
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from './api'
import logoHorizontal from '../../../assets/brand/logo_elo_horizontal_20261102.svg'
import logoSymbol from '../../../assets/brand/logo_elo_symbol_20261102.svg'
import communityPhoto from '../../../assets/brand/design_elo_community-photo_20261102.png'

const currentUser = ref(null)
const isLoading = ref(true)
const isSubmittingAuth = ref(false)
const isRegistering = ref(false)
const username = ref('')
const password = ref('')
const authError = ref('')
const authNotice = ref('')
const appError = ref('')
const feed = ref([])
const nextCursor = ref('')
const isLoadingFeed = ref(false)
const postText = ref('')
const isPublishing = ref(false)
const operationMessage = ref('')
const operationError = ref('')
const searchTerm = ref('')
const searchInput = ref(null)
const searchResults = ref([])
const isSearching = ref(false)
const followingIds = ref(new Set())
const isLoggingOut = ref(false)
const sharingIds = ref(new Set())

const remainingChars = computed(() => 500 - postText.value.length)
const apiAddress = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'

function errorMessage(error) {
  if (error instanceof ApiError) return error.message
  return 'Ocorreu um erro inesperado. Tente novamente.'
}

async function loadSession() {
  isLoading.value = true
  try {
    currentUser.value = await api.session()
    await loadFeed()
  } catch (error) {
    if (!(error instanceof ApiError) || ![401, 404].includes(error.status)) appError.value = errorMessage(error)
  } finally {
    isLoading.value = false
  }
}

async function loadFeed({ append = false } = {}) {
  isLoadingFeed.value = true
  appError.value = ''
  try {
    const page = await api.feed(append ? nextCursor.value : '')
    feed.value = append ? [...feed.value, ...(page.data || [])] : (page.data || [])
    nextCursor.value = page.pagination?.next_cursor || ''
  } catch (error) {
    appError.value = errorMessage(error)
  } finally {
    isLoadingFeed.value = false
  }
}

async function submitAuth() {
  if (isSubmittingAuth.value) return
  isSubmittingAuth.value = true
  authError.value = ''
  authNotice.value = ''
  try {
    if (isRegistering.value) {
      await api.register(username.value.trim(), password.value)
      isRegistering.value = false
      password.value = ''
      authNotice.value = 'Conta criada. Entre com seu nome de usuário e senha.'
      return
    }
    currentUser.value = await api.login(username.value.trim(), password.value)
    appError.value = ''
    feed.value = []
    await loadFeed()
  } catch (error) {
    authError.value = errorMessage(error)
  } finally {
    isSubmittingAuth.value = false
  }
}

async function logout() {
  isLoggingOut.value = true
  appError.value = ''
  try {
    await api.logout()
    currentUser.value = null
    feed.value = []
    searchResults.value = []
    followingIds.value = new Set()
    username.value = ''
    password.value = ''
  } catch (error) {
    appError.value = errorMessage(error)
  } finally {
    isLoggingOut.value = false
  }
}

async function searchUsers() {
  if (!searchTerm.value.trim()) {
    searchResults.value = []
    return
  }
  isSearching.value = true
  appError.value = ''
  try {
    searchResults.value = await api.searchUsers(searchTerm.value.trim())
  } catch (error) {
    appError.value = errorMessage(error)
  } finally {
    isSearching.value = false
  }
}

function focusSearch() {
  searchInput.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  searchInput.value?.focus({ preventScroll: true })
}

async function toggleFollow(user) {
  appError.value = ''
  const isFollowing = followingIds.value.has(user.id)
  try {
    if (isFollowing) {
      await api.unfollow(user.id)
      followingIds.value.delete(user.id)
    } else {
      await api.follow(user.id)
      followingIds.value.add(user.id)
    }
    followingIds.value = new Set(followingIds.value)
    await loadFeed()
  } catch (error) {
    appError.value = errorMessage(error)
  }
}

async function publish() {
  if (!postText.value.trim() || remainingChars.value < 0) return
  isPublishing.value = true
  operationMessage.value = 'Enviando sua publicação…'
  operationError.value = ''
  appError.value = ''
  try {
    const operation = await api.publish(postText.value.trim())
    postText.value = ''
    void finishOperation(operation, 'Publicação enviada.', 'Sua publicação está sendo processada…')
  } catch (error) {
    operationMessage.value = ''
    operationError.value = errorMessage(error)
  } finally {
    isPublishing.value = false
  }
}

async function share(item) {
  if (sharingIds.value.has(item.post.id)) return
  sharingIds.value = new Set(sharingIds.value).add(item.post.id)
  operationMessage.value = 'Enviando compartilhamento…'
  operationError.value = ''
  appError.value = ''
  try {
    const operation = await api.share(item.post.id)
    void finishOperation(operation, 'Compartilhamento enviado.', 'Enviando compartilhamento…')
  } catch (error) {
    operationMessage.value = ''
    operationError.value = errorMessage(error)
  } finally {
    const next = new Set(sharingIds.value)
    next.delete(item.post.id)
    sharingIds.value = next
  }
}

async function finishOperation(operation, successMessage, pendingMessage) {
  if (operation.status === 'completed') {
    operationMessage.value = successMessage
    await loadFeed()
    window.setTimeout(() => {
      if (operationMessage.value === successMessage) operationMessage.value = ''
    }, 4000)
    return
  }

  operationMessage.value = operation.status === 'pending' ? pendingMessage : 'A solicitação foi recebida.'
  if (!operation.status_url) return

  void pollOperation(operation.status_url, successMessage)
}

async function pollOperation(statusUrl, successMessage) {
  while (true) {
    await new Promise((resolve) => window.setTimeout(resolve, 1500))
    try {
      const result = await api.operation(statusUrl)
      if (result.status === 'pending') continue
      if (result.status === 'completed') {
        operationMessage.value = successMessage
        await loadFeed()
        window.setTimeout(() => {
          if (operationMessage.value === successMessage) operationMessage.value = ''
        }, 4000)
      } else {
        operationMessage.value = ''
        operationError.value = result.error?.message || 'A publicação não foi concluída. Tente novamente.'
      }
      return
    } catch (error) {
      operationMessage.value = ''
      operationError.value = errorMessage(error)
      return
    }
  }
}

function formatDate(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function isOwnPost(item) {
  return item.post?.author?.id === currentUser.value?.id
}

onMounted(loadSession)
</script>

<template>
  <main v-if="!currentUser" class="auth-page">
    <section class="auth-story" aria-label="Sobre a rede Elo">
      <img class="auth-photo" :src="communityPhoto" alt="Pessoas conversam ao redor de uma mesa" />
      <div class="auth-story-shade"></div>
      <a class="auth-logo-link" href="#" aria-label="Elo, início"><img :src="logoHorizontal" alt="Elo — Ideias encontram pessoas" /></a>
      <div class="auth-story-copy">
        <p class="story-kicker">Uma rede feita de encontros</p>
        <h1>Ideias encontram pessoas.</h1>
        <p>Acompanhe quem você escolheu, compartilhe o que importa e deixe boas conversas seguirem adiante.</p>
      </div>
      <span class="photo-caption">Conversas que aproximam.</span>
    </section>

    <section class="auth-panel" aria-labelledby="auth-title">
      <img class="auth-mark" :src="logoSymbol" alt="" />
      <div class="auth-form-wrap">
        <p class="eyebrow">Seu espaço de conversa</p>
        <h2 id="auth-title">{{ isRegistering ? 'Chegue mais perto.' : 'Que bom ter você por aqui.' }}</h2>
        <p class="auth-intro">{{ isRegistering ? 'Crie sua conta e comece a acompanhar novas ideias.' : 'Entre para ver o que as pessoas da sua rede estão compartilhando.' }}</p>

        <div v-if="authNotice" class="notice notice-success" role="status">{{ authNotice }}</div>
        <div v-if="authError" class="notice notice-error" role="alert">{{ authError }}</div>
        <div v-if="appError" class="notice notice-error" role="alert">{{ appError }}</div>

        <form class="auth-form" @submit.prevent="submitAuth">
          <label for="username">Nome de usuário</label>
          <input id="username" v-model.trim="username" name="username" autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" placeholder="ex.: ana_silva" required />
          <span class="field-hint">Use de 3 a 30 letras, números ou sublinhados.</span>

          <label for="password">Senha</label>
          <input id="password" v-model="password" name="password" :autocomplete="isRegistering ? 'new-password' : 'current-password'" minlength="8" maxlength="128" type="password" placeholder="Pelo menos 8 caracteres" required />

          <button class="button button-primary auth-submit" type="submit" :disabled="isLoading || isSubmittingAuth">
            {{ isSubmittingAuth ? 'Enviando…' : isRegistering ? 'Criar conta' : 'Entrar' }}
          </button>
        </form>

        <p class="auth-switch">
          {{ isRegistering ? 'Já tem uma conta?' : 'Ainda não faz parte?' }}
          <button type="button" class="text-button" @click="isRegistering = !isRegistering; authError = ''; authNotice = ''">
            {{ isRegistering ? 'Entrar' : 'Criar conta' }}
          </button>
        </p>
        <p class="api-footnote">API configurada: <code>{{ apiAddress }}</code></p>
      </div>
    </section>
  </main>

  <div v-else class="app-shell">
    <header class="mobile-header">
      <img :src="logoHorizontal" alt="Elo" />
      <button class="text-button" type="button" :disabled="isLoggingOut" @click="logout">Sair</button>
    </header>

    <aside class="left-rail">
      <img class="rail-logo" :src="logoHorizontal" alt="Elo — Ideias encontram pessoas" />
      <nav aria-label="Navegação principal">
        <a class="nav-link active" href="#feed" aria-current="page"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" /></svg></span>Início</a>
        <a class="nav-link" href="#discover" @click.prevent="focusSearch"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.5" /><path d="m16 16 4.5 4.5" /></svg></span>Encontrar pessoas</a>
      </nav>
      <div class="rail-profile">
        <div class="avatar avatar-small">{{ currentUser.username?.slice(0, 1).toUpperCase() }}</div>
        <div class="profile-name"><strong>{{ currentUser.username }}</strong><span>@{{ currentUser.username }}</span></div>
        <button class="logout-button" type="button" :disabled="isLoggingOut" aria-label="Sair da conta" @click="logout">Sair</button>
      </div>
    </aside>

    <main id="feed" class="timeline">
      <header class="timeline-header">
        <div><p class="eyebrow">Sua rede, em movimento</p><h1>Início</h1></div>
        <button class="icon-button" type="button" aria-label="Atualizar publicações" :disabled="isLoadingFeed" @click="loadFeed()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M5.6 9a7 7 0 0 1 11.8-2L20 12M4 12l2.6 5a7 7 0 0 0 11.8-2" /></svg>
        </button>
      </header>

      <div v-if="appError" class="notice notice-error app-notice" role="alert">
        <span>{{ appError }}</span><button class="text-button" type="button" @click="loadFeed()">Tentar novamente</button>
      </div>

      <section class="composer" aria-label="Criar publicação">
        <div class="avatar">{{ currentUser.username?.slice(0, 1).toUpperCase() }}</div>
        <form class="composer-form" @submit.prevent="publish">
          <label for="post-text">O que você quer compartilhar?</label>
          <textarea id="post-text" v-model="postText" maxlength="500" rows="3" placeholder="Uma ideia pode começar uma conversa…" :disabled="isPublishing"></textarea>
          <div class="composer-actions">
            <span :class="{ 'char-limit': remainingChars < 40 }">{{ remainingChars }} caracteres</span>
            <button class="button button-primary" type="submit" :disabled="isPublishing || !postText.trim() || remainingChars < 0">
              {{ isPublishing ? 'Enviando…' : 'Publicar' }}
            </button>
          </div>
        </form>
      </section>

      <div v-if="operationMessage" class="notice notice-pending" role="status"><span class="pending-dot"></span>{{ operationMessage }}</div>
      <div v-if="operationError" class="notice notice-error" role="alert">{{ operationError }}<button class="text-button" type="button" @click="operationError = ''">Fechar</button></div>

      <section class="feed-list" aria-label="Publicações recentes" aria-live="polite">
        <div v-if="isLoadingFeed && !feed.length" class="feed-state"><span class="spinner"></span><p>Carregando sua rede…</p></div>
        <article v-for="item in feed" :key="`${item.kind}-${item.id}`" class="post-card">
          <p v-if="item.kind === 'share'" class="share-label"><span aria-hidden="true">↗</span>{{ item.actor.username }} compartilhou</p>
          <div class="post-heading">
            <div class="avatar">{{ item.post.author.username?.slice(0, 1).toUpperCase() }}</div>
            <div class="post-byline"><strong>{{ item.post.author.username }}</strong><span>@{{ item.post.author.username }} <span aria-hidden="true">·</span> <time :datetime="item.created_at">{{ formatDate(item.created_at) }}</time></span></div>
            <span v-if="item.kind === 'share'" class="post-type">Compartilhamento</span>
          </div>
          <p class="post-content">{{ item.post.content }}</p>
          <div class="post-actions">
            <button v-if="!isOwnPost(item)" class="share-button" type="button" :disabled="sharingIds.has(item.post.id)" @click="share(item)"><span aria-hidden="true">↗</span>{{ sharingIds.has(item.post.id) ? 'Enviando…' : 'Compartilhar' }}</button>
          </div>
        </article>

        <div v-if="!isLoadingFeed && !feed.length" class="empty-feed">
          <div class="empty-mark"><img :src="logoSymbol" alt="" /></div>
          <h2>Seu feed começa com uma conversa.</h2>
          <p>Quando você publicar ou seguir alguém, as ideias da sua rede aparecem aqui.</p>
          <a href="#discover" class="text-link" @click.prevent="focusSearch">Encontrar pessoas</a>
        </div>
        <button v-if="nextCursor" class="load-more" type="button" :disabled="isLoadingFeed" @click="loadFeed({ append: true })">
          {{ isLoadingFeed ? 'Carregando…' : 'Ver publicações anteriores' }}
        </button>
      </section>
    </main>

    <aside id="discover" class="right-rail">
      <section class="search-panel">
        <p class="eyebrow">A rede cresce por escolha</p>
        <h2>Encontre pessoas</h2>
        <form class="search-form" @submit.prevent="searchUsers">
          <label class="sr-only" for="people-search">Buscar nome de usuário</label>
          <input id="people-search" ref="searchInput" v-model="searchTerm" type="search" placeholder="Buscar nome de usuário" />
          <button type="submit" aria-label="Buscar" :disabled="isSearching">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.5" /><path d="m16 16 4.5 4.5" /></svg>
          </button>
        </form>
        <p v-if="isSearching" class="search-hint">Procurando…</p>
        <p v-else-if="searchTerm && !searchResults.length" class="search-hint">Busque pelo nome de usuário para encontrar pessoas.</p>
        <ul v-if="searchResults.length" class="people-list">
          <li v-for="person in searchResults" :key="person.id" class="person-row">
            <div class="avatar avatar-small">{{ person.username?.slice(0, 1).toUpperCase() }}</div>
            <div class="profile-name"><strong>{{ person.username }}</strong><span>@{{ person.username }}</span></div>
            <button v-if="person.id !== currentUser.id" class="follow-button" type="button" :class="{ following: followingIds.has(person.id) }" @click="toggleFollow(person)">
              {{ followingIds.has(person.id) ? 'Seguindo' : 'Seguir' }}
            </button>
          </li>
        </ul>
      </section>
      <section class="brand-note">
        <img :src="communityPhoto" alt="" />
        <div><strong>Ideias encontram pessoas.</strong><p>Compartilhe com quem escolheu acompanhar você.</p></div>
      </section>
      <p class="footer-note">Elo · Um espaço para boas conversas</p>
    </aside>
  </div>
</template>
