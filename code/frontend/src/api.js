const baseUrl = (import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000').replace(/\/$/, '')

export class ApiError extends Error {
  constructor(message, status, code = 'request_failed') {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
  }
}

async function request(path, options = {}) {
  const headers = new Headers(options.headers || {})
  let csrfToken

  if (options.method && options.method !== 'GET') {
    let csrfResponse
    try {
      csrfResponse = await fetch(apiUrl('/api/csrf'), { credentials: 'include' })
    } catch {
      throw new ApiError(`Não foi possível conectar à API em ${baseUrl}.`, 0, 'connection_failed')
    }
    const csrfBody = await csrfResponse.json().catch(() => ({}))
    csrfToken = csrfBody.data?.token
    if (!csrfResponse.ok || !csrfToken) {
      const csrfRouteMissing = csrfResponse.status === 404 && /route\s+api\/csrf/i.test(csrfBody.message || '')
      const message = csrfBody.error?.message || (csrfRouteMissing
        ? 'Esta API ainda não implementa a rota de sessão segura (/api/csrf); login e cadastro dependem da implementação do contrato compartilhado.'
        : csrfBody.message || 'Não foi possível iniciar uma sessão segura.')
      throw new ApiError(message, csrfResponse.status, csrfBody.error?.code || (csrfRouteMissing ? 'csrf_route_unavailable' : undefined))
    }
    headers.set('X-CSRF-Token', csrfToken)
  }

  if (options.body) {
    headers.set('Content-Type', 'application/json')
  }

  let response
  try {
    response = await fetch(apiUrl(path), {
      ...options,
      headers,
      credentials: 'include',
    })
  } catch {
    throw new ApiError(`Não foi possível conectar à API em ${baseUrl}.`, 0, 'connection_failed')
  }

  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    const validationMessage = Object.values(body.error?.details || body.errors || {}).flat()[0]
    throw new ApiError(body.error?.message || body.message || validationMessage || 'A API não conseguiu concluir esta solicitação.', response.status, body.error?.code)
  }

  return { body, status: response.status }
}

function apiUrl(path) {
  return import.meta.env.DEV ? path : `${baseUrl}${path}`
}

export const api = {
  async session() {
    const { body } = await request('/api/session')
    return body.data
  },
  async register(username, password) {
    const { body } = await request('/api/users', { method: 'POST', body: JSON.stringify({ username, password }) })
    return body.data
  },
  async login(username, password) {
    const { body } = await request('/api/sessions', { method: 'POST', body: JSON.stringify({ username, password }) })
    return body.data
  },
  async logout() {
    await request('/api/session', { method: 'DELETE' })
  },
  async feed(cursor = '') {
    const query = new URLSearchParams({ limit: '20' })
    if (cursor) query.set('cursor', cursor)
    const { body } = await request(`/api/feed?${query}`)
    return body
  },
  async searchUsers(search) {
    const query = new URLSearchParams({ search, limit: '8' })
    const { body } = await request(`/api/users?${query}`)
    return body.data || []
  },
  async follow(userId) {
    await request('/api/follows', { method: 'POST', body: JSON.stringify({ followed_id: userId }) })
  },
  async unfollow(userId) {
    await request(`/api/follows/${encodeURIComponent(userId)}`, { method: 'DELETE' })
  },
  async publish(content) {
    const result = await request('/api/posts', { method: 'POST', body: JSON.stringify({ content }) })
    return operationResult(result)
  },
  async share(postId) {
    const result = await request('/api/shares', { method: 'POST', body: JSON.stringify({ post_id: postId }) })
    return operationResult(result)
  },
  async operation(statusUrl) {
    const url = new URL(statusUrl, `${baseUrl}/`)
    if (url.origin !== new URL(baseUrl).origin) {
      throw new ApiError('A API retornou um endereço de acompanhamento inválido.', 0, 'invalid_status_url')
    }
    const { body } = await request(`${url.pathname}${url.search}`)
    return body.data
  },
}

function operationResult({ body, status }) {
  const operation = body.data
  return { ...operation, status: status === 202 ? 'pending' : operation?.status }
}
