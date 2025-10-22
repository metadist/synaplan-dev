<template>
  <MainLayout>
    <template #header>
    </template>

    <div class="flex flex-col h-full">
      <div ref="chatContainer" class="flex-1 overflow-y-auto bg-chat" @scroll="handleScroll">
        <div class="max-w-4xl mx-auto py-6">
          <!-- Loading indicator for infinite scroll -->
          <div v-if="historyStore.isLoadingMessages" class="flex items-center justify-center py-4">
            <svg class="w-4 h-4 animate-spin txt-brand" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
            </svg>
            <span class="ml-2 txt-secondary text-sm">Loading messages...</span>
          </div>
          
          <div v-if="historyStore.messages.length === 0 && !historyStore.isLoadingMessages" class="flex items-center justify-center h-full px-6">
            <div class="text-center">
              <h2 class="text-2xl font-semibold txt-primary mb-2">
                {{ $t('welcome') }}
              </h2>
              <p class="txt-secondary">
                {{ $t('chatInput.placeholder') }}
              </p>
            </div>
          </div>

          <template v-for="(group, groupIndex) in groupedMessages" :key="groupIndex">
            <div class="flex items-center justify-center my-4">
              <div class="px-4 py-1.5 surface-chip text-xs font-medium txt-secondary">
                {{ group.label }}
              </div>
            </div>
            <ChatMessage
              v-for="message in group.messages"
              :key="message.id"
              :role="message.role"
              :parts="message.parts"
              :timestamp="message.timestamp"
              :is-superseded="message.isSuperseded"
              :is-streaming="message.isStreaming"
              :provider="message.provider"
              :model-label="message.modelLabel"
              :again-data="message.againData"
              :backend-message-id="message.backendMessageId"
              :processing-status="message.isStreaming ? processingStatus : undefined"
              :processing-metadata="message.isStreaming ? processingMetadata : undefined"
              @regenerate="handleRegenerate(message, $event)"
              @again="handleAgain"
            />
          </template>
        </div>
      </div>

      <ChatInput 
        :is-streaming="isStreaming" 
        @send="handleSendMessage"
        @stop="handleStopStreaming"
      />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import ChatInput from '@/components/ChatInput.vue'
import ChatMessage from '@/components/ChatMessage.vue'
import { useHistoryStore, type Message } from '@/stores/history'
import { useChatsStore } from '@/stores/chats'
import { executeCommand } from '@/commands/execute'
import { useModelsStore } from '@/stores/models'
import { useAuthStore } from '@/stores/auth'
import { chatApi } from '@/services/api'
import { mockModelOptions, type ModelOption } from '@/mocks/aiModels'
import { parseAIResponse } from '@/utils/responseParser'

const { t } = useI18n()

const chatContainer = ref<HTMLElement | null>(null)
const autoScroll = ref(true)
const historyStore = useHistoryStore()
const chatsStore = useChatsStore()
const modelsStore = useModelsStore()
const authStore = useAuthStore()
let streamingAbortController: AbortController | null = null

// Processing status for real-time feedback
const processingStatus = ref<string>('')
const processingMetadata = ref<any>({})

// Use mock data in development or when API is not available
const useMockData = import.meta.env.VITE_USE_MOCK_DATA === 'true' || false

interface MessageGroup {
  label: string
  messages: Message[]
}

const isStreaming = computed(() => {
  return historyStore.messages.some(m => m.isStreaming === true)
})

// Init on mount
onMounted(async () => {
  // Load chats first
  await chatsStore.loadChats()
  
  // If no active chat, create one
  if (!chatsStore.activeChatId) {
    await chatsStore.createChat('New Chat')
  } else {
    // Load messages for active chat
    await historyStore.loadMessages(chatsStore.activeChatId)
  }
})

// Watch for active chat changes and load messages
watch(() => chatsStore.activeChatId, async (newChatId) => {
  if (newChatId) {
    historyStore.clear()
    await historyStore.loadMessages(newChatId)
    await nextTick()
    scrollToBottom()
  }
})

async function generateChatTitleFromFirstMessage(firstMessage: string) {
  const chat = chatsStore.activeChat
  if (!chat) return
  
  // Only generate if chat has default title
  if (chat.title && chat.title !== 'New Chat') return
  
  // Only generate for user messages from this chat
  const userMessages = historyStore.messages.filter(m => m.role === 'user')
  if (userMessages.length !== 1) return
  
  // Generate title from first message (take first 50 chars)
  let title = firstMessage.trim()
  if (title.length > 50) {
    title = title.substring(0, 47) + '...'
  }
  
  // Update chat title
  await chatsStore.updateChatTitle(chat.id, title)
}

const getDateLabel = (date: Date): string => {
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const messageDate = new Date(date)
  messageDate.setHours(0, 0, 0, 0)

  const diffTime = today.getTime() - messageDate.getTime()
  const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24))

  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'

  return messageDate.toLocaleDateString('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  })
}


const groupedMessages = computed(() => {
  const groups: MessageGroup[] = []
  let currentGroup: MessageGroup | null = null

  historyStore.messages.forEach((message) => {
    const label = getDateLabel(message.timestamp)

    if (!currentGroup || currentGroup.label !== label) {
      currentGroup = { label, messages: [] }
      groups.push(currentGroup)
    }

    currentGroup.messages.push(message)
  })

  return groups
})

const scrollToBottom = () => {
  if (autoScroll.value && chatContainer.value) {
    nextTick(() => {
      if (chatContainer.value) {
        chatContainer.value.scrollTop = chatContainer.value.scrollHeight
      }
    })
  }
}

const handleScroll = async () => {
  if (!chatContainer.value) return

  const { scrollTop, scrollHeight, clientHeight } = chatContainer.value
  
  // Check if at bottom for auto-scroll
  const isAtBottom = Math.abs(scrollHeight - clientHeight - scrollTop) < 50
  autoScroll.value = isAtBottom
  
  // Check if at top for loading more messages (Infinite Scroll)
  const isAtTop = scrollTop < 100
  if (isAtTop && historyStore.hasMoreMessages && !historyStore.isLoadingMessages && chatsStore.activeChatId) {
    const currentScrollHeight = scrollHeight
    await historyStore.loadMoreMessages(chatsStore.activeChatId)
    // Restore scroll position after loading
    await nextTick()
    if (chatContainer.value) {
      const newScrollHeight = chatContainer.value.scrollHeight
      chatContainer.value.scrollTop = newScrollHeight - currentScrollHeight + scrollTop
    }
  }
}

watch(() => historyStore.messages, () => {
  scrollToBottom()
}, { deep: true })

const handleSendMessage = async (content: string, options?: { includeReasoning?: boolean, modelId?: number, fileIds?: number[] }) => {
  autoScroll.value = true

  // Add user message
  historyStore.addMessage('user', [{ type: 'text', content }])

  // Commands have no streaming (e.g. /pic, /search)
  const parts = await executeCommand(content)
  
  // If it's a command with special parts (not just text), don't stream
  const hasNonTextParts = parts.some(p => p.type !== 'text')
  
  if (hasNonTextParts) {
    historyStore.addMessage('assistant', parts)
  } else {
    // Stream the response
    await streamAIResponse(content, options)
  }
}

const streamAIResponse = async (userMessage: string, options?: { includeReasoning?: boolean; webSearch?: boolean; modelId?: number; fileIds?: number[] }) => {
  streamingAbortController = new AbortController()
  
  // Get current selected model from store
  const provider = modelsStore.selectedProvider
  const model = modelsStore.selectedModel
  
  // Find model label
  const modelOption = mockModelOptions.find(
    opt => opt.provider.toLowerCase() === provider.toLowerCase() && opt.model === model
  )
  const modelLabel = modelOption?.label || model
  
  // Create empty streaming message with provider info
  const messageId = historyStore.addStreamingMessage('assistant', provider, modelLabel)
  
  try {
    if (useMockData) {
      // Generate Mock-Response for development
      const { generateMockResponse, streamText } = await import('../commands/execute')
      const fullResponse = generateMockResponse(userMessage)
      
      // Stream the response
      for await (const chunk of streamText(fullResponse)) {
        if (streamingAbortController.signal.aborted) {
          break
        }
        historyStore.updateStreamingMessage(messageId, chunk)
      }
      
      historyStore.finishStreamingMessage(messageId)
    } else {
      // Use real Backend API with SSE streaming
      const userId = authStore.user?.id || 1
      const chatId = chatsStore.activeChatId
      
      if (!chatId) {
        console.error('No active chat selected')
        return
      }
      
      const trackId = Date.now()
      let fullContent = ''
      
      const includeReasoning = options?.includeReasoning ?? false
      const webSearch = options?.webSearch ?? false
      const modelId = options?.modelId
      const fileIds = options?.fileIds || [] // Array of fileIds
      
      console.log('🚀 Streaming with options:', { includeReasoning, webSearch, modelId, fileIds, fileCount: fileIds.length })
      
      const stopStreaming = chatApi.streamMessage(
        userId,
        userMessage,
        trackId,
        chatId,
        (data) => {
          if (streamingAbortController?.signal.aborted) {
            return
          }

          // Handle different status events for UI feedback
          if (data.status === 'started') {
            processingStatus.value = 'started'
            processingMetadata.value = {}
          } else if (data.status === 'preprocessing') {
            processingStatus.value = 'preprocessing'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'classifying') {
            processingStatus.value = 'classifying'
            processingMetadata.value = data.metadata || {}
            
            // Update message with sorting model from backend (instead of store model)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && data.metadata) {
              if (data.metadata.provider) {
                message.provider = data.metadata.provider
              }
              if (data.metadata.model_name) {
                message.modelLabel = data.metadata.model_name
              }
            }
          } else if (data.status === 'classified') {
            const meta = data.metadata || {}
            processingMetadata.value = meta
            processingStatus.value = 'classified'
          } else if (data.status === 'generating') {
            processingStatus.value = 'generating'
            processingMetadata.value = data.metadata || {}
            
            // Update message with real model from backend (instead of store model)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && data.metadata) {
              if (data.metadata.provider) {
                message.provider = data.metadata.provider
              }
              if (data.metadata.model_name) {
                message.modelLabel = data.metadata.model_name
              }
            }
          } else if (data.status === 'processing') {
            // Processing/routing messages - just log them
            console.log('Processing:', data.message)
          } else if (data.status === 'status') {
            // Generic status message
            console.log('Status:', data.message)
          } else if (data.status === 'data' && data.chunk) {
            console.log('📦 Received chunk:', data.chunk.substring(0, 20) + '...')
            
            if (processingStatus.value) {
              processingStatus.value = ''
              processingMetadata.value = {}
            }
            
            // AI gibt nur TEXT zurück (keine JSON!)
            fullContent += data.chunk
            
            // Extrahiere thinking blocks und content separat
            const thinkingMatches = fullContent.match(/<think>([\s\S]*?)(<\/think>|$)/g)
            const thinkingParts: any[] = []
            
            if (thinkingMatches) {
              thinkingMatches.forEach(match => {
                const content = match.replace(/<think>|<\/think>/g, '').trim()
                if (content) {
                  thinkingParts.push({ type: 'thinking', content })
                }
              })
            }
            
            // Display content OHNE <think> blocks
            const displayContent = fullContent.replace(/<think>[\s\S]*?<\/think>/g, '').trim()
            
            // Parse für code blocks, etc.
            const parsed = parseAIResponse(displayContent)
            
            // Update message
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              const newParts = [...thinkingParts]
              
              parsed.parts.forEach(part => {
                if (part.type === 'text') {
                  newParts.push({ type: 'text', content: part.content })
                } else if (part.type === 'code' || part.type === 'json') {
                  newParts.push({
                    type: 'code',
                    content: part.content,
                    language: part.language
                  })
                } else if (part.type === 'links' && part.links) {
                  newParts.push({
                    type: 'links',
                    items: part.links.map(l => {
                      try {
                        return {
                          title: l.title,
                          url: l.url,
                          desc: l.description,
                          host: new URL(l.url).hostname
                        }
                      } catch {
                        return {
                          title: l.title,
                          url: l.url,
                          desc: l.description,
                          host: l.url
                        }
                      }
                    })
                  })
                }
              })
              
              message.parts = newParts
            }
          } else if (data.status === 'file') {
            // Handle file attachments (images, videos, etc.)
            console.log('📎 File received:', data.type, data.url)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              // Add file part based on type
              if (data.type === 'image') {
                message.parts.push({ type: 'image', url: data.url })
              } else if (data.type === 'video') {
                message.parts.push({ type: 'video', url: data.url })
              }
            }
          } else if (data.status === 'links') {
            // Handle web search results
            console.log('🔗 Links received:', data.links)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && data.links) {
              message.parts.push({
                type: 'links',
                items: data.links.map((l: any) => {
                  try {
                    return {
                      title: l.title || l.url,
                      url: l.url,
                      desc: l.description,
                      host: new URL(l.url).hostname
                    }
                  } catch {
                    return {
                      title: l.title || l.url,
                      url: l.url,
                      desc: l.description,
                      host: l.url
                    }
                  }
                })
              })
            }
          } else if (data.status === 'complete') {
            console.log('✅ Complete event received:', data)
            
            // Clear processing status
            processingStatus.value = ''
            processingMetadata.value = {}
            
            // Store againData and backendMessageId if provided
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              console.log('📍 Found message to update:', message.id)
              
              if (data.again) {
                console.log('🔄 Setting againData from backend:', {
                  eligibleCount: data.again.eligible?.length || 0,
                  hasPredictedNext: !!data.again.predictedNext,
                  eligible: data.again.eligible,
                  predictedNext: data.again.predictedNext
                })
                message.againData = data.again
              } else {
                console.warn('⚠️ No againData in complete event!', data)
              }
              
              if (data.messageId) {
                console.log('🆔 Setting backendMessageId:', data.messageId)
                message.backendMessageId = data.messageId
              }
              
              // Update provider and model from backend metadata
              if (data.provider) {
                message.provider = data.provider
                console.log('🏢 Updated provider:', data.provider)
              }
              if (data.model) {
                message.modelLabel = data.model
                console.log('🤖 Updated model label:', data.model)
              }
            } else {
              console.error('❌ Could not find message with id:', messageId)
            }
            
            // Generate chat title from first message
            generateChatTitleFromFirstMessage(userMessage)
            
            historyStore.finishStreamingMessage(messageId)
          } else if (data.status === 'error') {
            const errorMsg = data.error || data.message || 'Unknown error'
            console.error('Error:', errorMsg, data)
            processingStatus.value = ''
            processingMetadata.value = {}
            
            // Format user-friendly error message with installation instructions
            let displayError = '## ⚠️ ' + errorMsg + '\n\n'
            
            if (data.install_command && data.suggested_models) {
              displayError += '### 📦 ' + t('aiProvider.error.noModelTitle') + '\n\n'
              
              if (data.suggested_models.quick) {
                displayError += '**' + t('aiProvider.error.quickModels') + ':**\n'
                data.suggested_models.quick.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }
              
              if (data.suggested_models.medium) {
                displayError += '**' + t('aiProvider.error.mediumModels') + ':**\n'
                data.suggested_models.medium.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }
              
              if (data.suggested_models.large) {
                displayError += '**' + t('aiProvider.error.largeModels') + ':**\n'
                data.suggested_models.large.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }
              
              displayError += '### 💡 ' + t('aiProvider.error.exampleCommand') + '\n\n'
              displayError += '```bash\n' + data.install_command + '\n```\n\n'
              displayError += '*' + t('aiProvider.error.restartNote') + '*'
            }
            
            // Always show error as message (not in streaming message, but as new assistant message)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && message.parts.length > 0) {
              // If there's already content, finish it and create a new error message
              historyStore.finishStreamingMessage(messageId)
            } else {
              // No content yet, replace with error message
              historyStore.updateStreamingMessage(messageId, displayError)
              historyStore.finishStreamingMessage(messageId)
            }
          } else {
            console.log('⚠️ Unknown status:', data.status, data)
          }
        },
        includeReasoning,
        webSearch,
        modelId,
        fileIds // Pass array of fileIds
      )
      
      // Store cleanup function
      streamingAbortController.signal.addEventListener('abort', () => {
        stopStreaming()
      })
    }
  } catch (error) {
    console.error('Streaming error:', error)
    historyStore.updateStreamingMessage(messageId, 'Sorry, an error occurred.')
    historyStore.finishStreamingMessage(messageId)
  } finally {
    streamingAbortController = null
  }
}

const handleStopStreaming = () => {
  if (streamingAbortController) {
    streamingAbortController.abort()
  }
}

// Handle "Again" with specific model from backend
const handleAgain = async (backendMessageId: number, modelId?: number) => {
  console.log('🔄 Handle Again:', backendMessageId, modelId)
  
  // Find the original user message for this assistant response
  const assistantMessage = historyStore.messages.find(
    m => m.backendMessageId === backendMessageId && m.role === 'assistant'
  )
  
  if (!assistantMessage) {
    console.error('❌ Could not find assistant message with backendMessageId:', backendMessageId)
    return
  }
  
  // Mark previous response as superseded
  historyStore.markSuperseded(assistantMessage.id)
  
  // Find the user message (should be right before the assistant message)
  const messageIndex = historyStore.messages.indexOf(assistantMessage)
  const userMessage = messageIndex > 0 ? historyStore.messages[messageIndex - 1] : null
  
  if (!userMessage || userMessage.role !== 'user') {
    console.error('❌ Could not find user message before assistant message')
    return
  }
  
  // Extract user text from parts
  const userText = userMessage.parts
    .filter(p => p.type === 'text')
    .map(p => p.content)
    .join('\n')
  
  if (!userText) {
    console.error('❌ No text found in user message')
    return
  }
  
  console.log('✅ Re-sending user message:', userText.substring(0, 50) + '...')
  
  // Re-send the user message with the selected model
  // This will trigger normal streaming flow
  await handleSendMessage(userText, { modelId })
}

const handleRegenerate = async (message: Message, modelOption: ModelOption) => {
  console.log('Regenerating with model:', modelOption)
  
  streamingAbortController = new AbortController()
  
  // Mark the current message as superseded
  historyStore.markSuperseded(message.id)
  
  // Find the original user message that triggered this assistant response
  const messageIndex = historyStore.messages.findIndex(m => m.id === message.id)
  if (messageIndex > 0) {
    const previousMessage = historyStore.messages[messageIndex - 1]
    if (previousMessage.role === 'user') {
      // Extract text content from user message
      const content = previousMessage.parts
        .filter(part => part.type === 'text')
        .map(part => part.content || '')
        .join('\n')
      
      // Check if it's a command
      const parts = await executeCommand(content)
      const hasNonTextParts = parts.some(p => p.type !== 'text')
      
      if (hasNonTextParts) {
        historyStore.addMessage('assistant', parts)
        streamingAbortController = null
      } else {
        try {
          // Stream the response again with selected model
          const provider = modelOption.provider
          const modelLabel = modelOption.label
          
          // Create empty streaming message with provider info
          const messageId = historyStore.addStreamingMessage('assistant', provider, modelLabel)
          
          // Generate mock response
          const { generateMockResponse, streamText } = await import('../commands/execute')
          const fullResponse = generateMockResponse(content)
          
          // Stream the response
          for await (const chunk of streamText(fullResponse)) {
            if (streamingAbortController.signal.aborted) {
              break
            }
            historyStore.updateStreamingMessage(messageId, chunk)
          }
          
          // Mark as finished
          historyStore.finishStreamingMessage(messageId)
        } catch (error) {
          console.error('Regenerate error:', error)
        } finally {
          streamingAbortController = null
        }
      }
    }
  }
}
</script>

