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

