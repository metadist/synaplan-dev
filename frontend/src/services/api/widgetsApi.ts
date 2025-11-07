import { httpClient } from './httpClient'

// Widget Interface
export interface Widget {
  id: number
  widgetId: string
  name: string
  taskPromptTopic: string
  status: 'active' | 'inactive'
  config: WidgetConfig
  isActive: boolean
  created: number
  updated: number
  stats?: {
    active_sessions: number
    total_messages: number
  }
}

export interface WidgetConfig {
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  primaryColor?: string
  iconColor?: string
  defaultTheme?: 'light' | 'dark'
  autoOpen?: boolean
  autoMessage?: string
  messageLimit?: number
  maxFileSize?: number
}

export interface CreateWidgetRequest {
  name: string
  taskPromptTopic: string
  config?: WidgetConfig
}

export interface UpdateWidgetRequest {
  name?: string
  config?: WidgetConfig
  status?: 'active' | 'inactive'
}

export interface EmbedCodeResponse {
  success: boolean
  embedCode: string
  wordpressShortcode: string
  widgetUrl: string
}

/**
 * List all widgets for current user
 */
export async function listWidgets(): Promise<Widget[]> {
  const data = await httpClient<{ success: boolean; widgets: Widget[] }>(
    '/api/v1/widgets',
    {
      method: 'GET'
    }
  )
  return data.widgets
}

/**
 * Create new widget
 */
export async function createWidget(request: CreateWidgetRequest): Promise<Widget> {
  const data = await httpClient<{ success: boolean; widget: Widget }>(
    '/api/v1/widgets',
    {
      method: 'POST',
      body: JSON.stringify(request)
    }
  )
  return data.widget
}

/**
 * Get widget details
 */
export async function getWidget(widgetId: string): Promise<Widget> {
  const data = await httpClient<{ success: boolean; widget: Widget }>(
    `/api/v1/widgets/${widgetId}`,
    {
      method: 'GET'
    }
  )
  return data.widget
}

/**
 * Update widget
 */
export async function updateWidget(
  widgetId: string,
  request: UpdateWidgetRequest
): Promise<void> {
  await httpClient<{ success: boolean }>(
    `/api/v1/widgets/${widgetId}`,
    {
      method: 'PUT',
      body: JSON.stringify(request)
    }
  )
}

/**
 * Delete widget
 */
export async function deleteWidget(widgetId: string): Promise<void> {
  await httpClient<{ success: boolean }>(
    `/api/v1/widgets/${widgetId}`,
    {
      method: 'DELETE'
    }
  )
}

/**
 * Get embed code for widget
 */
export async function getEmbedCode(widgetId: string): Promise<EmbedCodeResponse> {
  return await httpClient<EmbedCodeResponse>(
    `/api/v1/widgets/${widgetId}/embed`,
    {
      method: 'GET'
    }
  )
}

/**
 * Get widget statistics
 */
export async function getWidgetStats(widgetId: string): Promise<{
  active_sessions: number
  total_messages: number
}> {
  const data = await httpClient<{ success: boolean; stats: any }>(
    `/api/v1/widgets/${widgetId}/stats`,
    {
      method: 'GET'
    }
  )
  return data.stats
}

/**
 * Send message to widget (public endpoint - no auth required, SSE streaming)
 */
export async function sendWidgetMessage(
  widgetId: string,
  text: string,
  sessionId: string,
  chatId?: number,
  onChunk?: (chunk: string) => void
): Promise<{
  success: boolean
  messageId: number
  chatId: number
}> {
  const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000'
  
  return new Promise(async (resolve, reject) => {
    try {
      const response = await fetch(`${apiUrl}/api/v1/widget/${widgetId}/message`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          sessionId,
          text,
          ...(chatId ? { chatId } : {})
        })
      })

      if (!response.ok) {
        const error = await response.json().catch(() => ({ error: 'Unknown error' }))
        console.error('❌ Widget message API error:', error)
        reject(new Error(error.error || `HTTP ${response.status}`))
        return
      }

      const reader = response.body?.getReader()
      if (!reader) {
        reject(new Error('No response body'))
        return
      }

      const decoder = new TextDecoder()
      let buffer = ''
      let messageId: number | null = null
      let finalChatId: number | null = null

      while (true) {
        const { done, value } = await reader.read()
        if (done) break

        buffer += decoder.decode(value, { stream: true })
        const lines = buffer.split('\n')
        buffer = lines.pop() || ''

        for (const line of lines) {
          if (line.startsWith('event:')) {
            continue
          }

          if (line.startsWith('data:')) {
            const jsonStr = line.slice(5).trim()
            try {
              const data = JSON.parse(jsonStr)
              
              // Handle chunk data
              if (data.chunk && onChunk) {
                onChunk(data.chunk)
              }
              
              // Handle completion
              if (data.status === 'complete') {
                messageId = data.messageId
                finalChatId = data.chatId
              }
              
              // Handle error
              if (data.error) {
                reject(new Error(data.error))
                return
              }
            } catch (e) {
              console.error('Failed to parse SSE data:', e, jsonStr)
            }
          }
        }
      }

      if (messageId && finalChatId) {
        resolve({
          success: true,
          messageId,
          chatId: finalChatId
        })
      } else {
        reject(new Error('Invalid completion data'))
      }
    } catch (error) {
      reject(error)
    }
  })
}

