<template>
  <div :class="[isPreview ? 'absolute' : 'fixed', 'z-[9999]', positionClass]">
    <!-- Chat Button -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="scale-0 opacity-0"
      enter-to-class="scale-100 opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="scale-100 opacity-100"
      leave-to-class="scale-0 opacity-0"
    >
      <button
        v-if="!isOpen"
        @click="toggleChat"
        :style="{ backgroundColor: primaryColor }"
        class="w-16 h-16 rounded-full shadow-2xl hover:scale-110 transition-transform flex items-center justify-center group"
        aria-label="Open chat"
      >
        <ChatBubbleLeftRightIcon :style="{ color: iconColor }" class="w-8 h-8" />
        <span
          v-if="unreadCount > 0"
          class="absolute -top-1 -right-1 w-6 h-6 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center"
        >
          {{ unreadCount }}
        </span>
      </button>
    </Transition>

    <!-- Chat Window -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div
        v-if="isOpen"
        :class="[
          'flex flex-col overflow-hidden shadow-2xl',
          isMobile && !isPreview ? 'fixed inset-0' : 'rounded-2xl w-[400px] h-[600px]'
        ]"
        :style="{
          backgroundColor: widgetTheme === 'dark' ? '#1a1a1a' : '#ffffff'
        }"
      >
        <!-- Header -->
        <div
          :style="{ backgroundColor: primaryColor }"
          class="flex items-center justify-between px-4 py-3"
        >
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
              <ChatBubbleLeftRightIcon class="w-6 h-6 text-white" />
            </div>
            <div>
              <h3 class="text-white font-semibold">{{ widgetTitle || $t('widget.title') }}</h3>
              <p class="text-white/80 text-xs">{{ $t('widget.subtitle') }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="startNewConversation"
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              aria-label="Start new chat"
            >
              <ArrowPathIcon class="w-5 h-5 text-white" />
            </button>
            <button
              @click="toggleTheme"
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              :aria-label="widgetTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
            >
              <SunIcon v-if="widgetTheme === 'dark'" class="w-5 h-5 text-white" />
              <MoonIcon v-else class="w-5 h-5 text-white" />
            </button>
            <button
              @click="toggleChat"
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              aria-label="Close chat"
            >
              <XMarkIcon class="w-5 h-5 text-white" />
            </button>
          </div>
        </div>

        <!-- Messages -->
        <div
          ref="messagesContainer"
          class="flex-1 overflow-y-auto p-4 space-y-3"
          :style="{
            backgroundColor: widgetTheme === 'dark' ? '#1a1a1a' : '#ffffff'
          }"
        >
          <div
            v-for="message in messages"
            :key="message.id"
            :class="[
              'flex',
              message.role === 'user' ? 'justify-end' : 'justify-start'
            ]"
          >
            <div
              :class="[
                'max-w-[80%] rounded-2xl px-4 py-2',
                message.role === 'user' ? '' : ''
              ]"
              :style="message.role === 'user' 
                ? { backgroundColor: primaryColor, color: iconColor }
                : { backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }"
            >
              <p
                v-if="message.type === 'text'"
                class="text-sm whitespace-pre-wrap break-words"
                :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937') }"
              >
                {{ message.content }}
              </p>
              <div v-else-if="message.type === 'file'" class="flex items-center gap-2">
                <DocumentIcon class="w-5 h-5" :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937') }" />
                <span class="text-sm" :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937') }">
                  {{ message.fileName }}
                </span>
              </div>
              <p
                v-if="message.timestamp"
                class="text-xs mt-1 opacity-70"
                :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#9ca3af' : '#6b7280') }"
              >
                {{ formatTime(message.timestamp) }}
              </p>
            </div>
          </div>

          <div v-if="isTyping" class="flex justify-start">
            <div class="rounded-2xl px-4 py-3" :style="{ backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }">
              <div class="flex gap-1">
                <div class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce" style="animation-delay: 0ms"></div>
                <div class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce" style="animation-delay: 150ms"></div>
                <div class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce" style="animation-delay: 300ms"></div>
              </div>
            </div>
          </div>

          <!-- Limit Warning -->
          <div v-if="showLimitWarning" class="flex justify-center">
            <div class="bg-orange-500/10 border border-orange-500/30 rounded-xl px-4 py-3 max-w-[90%]">
              <div class="flex items-start gap-2">
                <ExclamationTriangleIcon class="w-5 h-5 text-orange-500 flex-shrink-0 mt-0.5" />
                <div>
                  <p class="text-sm font-medium txt-primary">{{ $t('widget.limitWarning') }}</p>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widget.limitDetails', { current: messageCount, max: messageLimit }) }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Limit Reached -->
          <div v-if="limitReached" class="flex justify-center">
            <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-4 py-3 max-w-[90%]">
              <div class="flex items-start gap-2">
                <XCircleIcon class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" />
                <div>
                  <p class="text-sm font-medium txt-primary">{{ $t('widget.limitReached') }}</p>
                  <p class="text-xs txt-secondary mt-1">{{ $t('widget.limitReachedDetails') }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Input Area -->
        <div class="border-t p-3" :style="{ borderColor: widgetTheme === 'dark' ? '#333' : '#e5e7eb' }">
          <div v-if="selectedFile" class="mb-2 flex items-center gap-2 p-2 rounded-lg" :style="{ backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }">
            <DocumentIcon class="w-5 h-5" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }" />
            <span class="text-sm flex-1 truncate" :style="{ color: widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937' }">{{ selectedFile.name }}</span>
            <span class="text-xs" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }">{{ formatFileSize(selectedFile.size) }}</span>
            <button
              @click="removeFile"
              class="w-6 h-6 rounded hover:bg-black/10 dark:hover:bg-white/10 flex items-center justify-center"
            >
              <XMarkIcon class="w-4 h-4 txt-secondary" />
            </button>
          </div>

          <!-- File Size Error -->
          <div v-if="fileSizeError" class="mb-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg">
            <div class="flex items-start gap-2">
              <ExclamationTriangleIcon class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-red-600 dark:text-red-400">
                {{ $t('widget.fileTooLarge', { max: maxFileSize }) }}
              </p>
            </div>
          </div>

          <div class="flex items-end gap-2">
            <input
              ref="fileInput"
              type="file"
              @change="handleFileSelect"
              accept="image/*,.pdf,.doc,.docx,.txt"
              class="hidden"
            />
            <button
              @click="fileInput?.click()"
              :disabled="limitReached"
              class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed"
              :aria-label="$t('widget.attachFile')"
            >
              <PaperClipIcon class="w-5 h-5" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }" />
            </button>
            <textarea
              v-model="inputMessage"
              @keydown.enter.exact.prevent="sendMessage"
              :disabled="limitReached"
              :placeholder="limitReached ? $t('widget.limitReachedPlaceholder') : $t('widget.placeholder')"
              rows="1"
              class="flex-1 px-4 py-2 rounded-lg resize-none focus:outline-none focus:ring-2 disabled:opacity-50 disabled:cursor-not-allowed"
              :style="{
                backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6',
                color: widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937',
                borderColor: primaryColor,
                maxHeight: '120px',
                minHeight: '40px'
              }"
            />
            <button
              @click="sendMessage"
              :disabled="!canSend"
              :style="canSend ? { backgroundColor: primaryColor } : {}"
              :class="[
                'w-10 h-10 rounded-lg transition-all flex items-center justify-center',
                canSend ? 'hover:scale-110 shadow-lg' : 'bg-gray-300 dark:bg-gray-600 cursor-not-allowed'
              ]"
              :aria-label="$t('widget.send')"
            >
              <PaperAirplaneIcon :class="['w-5 h-5', canSend ? 'text-white' : 'text-gray-500']" />
            </button>
          </div>
        </div>

        <!-- Powered By -->
        <div class="px-4 py-2 text-center border-t" :style="{ borderColor: widgetTheme === 'dark' ? '#333' : '#e5e7eb' }">
          <p class="text-xs" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }">
            Powered by <span class="font-semibold" :style="{ color: primaryColor }">synaplan</span>
          </p>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import {
  ChatBubbleLeftRightIcon,
  XMarkIcon,
  PaperAirplaneIcon,
  PaperClipIcon,
  DocumentIcon,
  SunIcon,
  MoonIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
  ArrowPathIcon
} from '@heroicons/vue/24/outline'

interface Props {
  widgetId: string
  primaryColor?: string
  iconColor?: string
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  autoOpen?: boolean
  autoMessage?: string
  messageLimit?: number
  maxFileSize?: number
  defaultTheme?: 'light' | 'dark'
  isPreview?: boolean
  widgetTitle?: string
  apiUrl?: string
}

const props = withDefaults(defineProps<Props>(), {
  primaryColor: '#007bff',
  iconColor: '#ffffff',
  position: 'bottom-right',
  autoOpen: false,
  autoMessage: 'Hello! How can I help you today?',
  messageLimit: 50,
  maxFileSize: 10,
  defaultTheme: 'light',
  isPreview: false,
  widgetTitle: ''
})

interface Message {
  id: string
  role: 'user' | 'assistant'
  type: 'text' | 'file'
  content: string
  fileName?: string
  timestamp: Date
}

const isOpen = ref(false)
const widgetTheme = ref<'light' | 'dark'>(props.defaultTheme)
const inputMessage = ref('')
const selectedFile = ref<File | null>(null)
const fileSizeError = ref(false)
const messages = ref<Message[]>([])
const isTyping = ref(false)
const unreadCount = ref(0)
const messagesContainer = ref<HTMLElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const messageCount = ref(0)
const sessionId = ref<string>('')
const isSending = ref(false)
const chatId = ref<number | null>(null)
const historyLoaded = ref(false)
const isLoadingHistory = ref(false)

const isMobile = computed(() => window.innerWidth < 768)

const positionClass = computed(() => {
  const positions = {
    'bottom-left': 'bottom-6 left-6',
    'bottom-right': 'bottom-6 right-6',
    'top-left': 'top-6 left-6',
    'top-right': 'top-6 right-6'
  }
  return positions[props.position]
})

const canSend = computed(() => {
  return !limitReached.value && !isSending.value && (inputMessage.value.trim() !== '' || selectedFile.value !== null)
})

const resolveApiUrl = () => props.apiUrl || import.meta.env.VITE_API_URL || 'http://localhost:8000'

const showLimitWarning = computed(() => {
  const warningThreshold = props.messageLimit * 0.8
  return messageCount.value >= warningThreshold && messageCount.value < props.messageLimit
})

const limitReached = computed(() => {
  return messageCount.value >= props.messageLimit
})

const ensureAutoMessage = () => {
  if (!historyLoaded.value) return
  if (messages.value.length === 0 && props.autoMessage) {
    addBotMessage(props.autoMessage)
  }
}

const openChat = () => {
  if (!isOpen.value) {
    isOpen.value = true
    unreadCount.value = 0
    ensureAutoMessage()
  }
}

const closeChat = () => {
  if (isOpen.value) {
    isOpen.value = false
  }
}

const toggleChat = () => {
  if (isOpen.value) {
    closeChat()
  } else {
    openChat()
  }
}

const toggleTheme = () => {
  widgetTheme.value = widgetTheme.value === 'dark' ? 'light' : 'dark'
}

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  
  if (file) {
    const fileSizeMB = file.size / (1024 * 1024)
    if (fileSizeMB > props.maxFileSize) {
      fileSizeError.value = true
      setTimeout(() => {
        fileSizeError.value = false
      }, 3000)
      target.value = ''
      return
    }
    selectedFile.value = file
    fileSizeError.value = false
  }
}

const removeFile = () => {
  selectedFile.value = null
}

const sendMessage = async () => {
  if (!canSend.value) return

  // Handle file upload
  if (selectedFile.value) {
    messages.value.push({
      id: Date.now().toString(),
      role: 'user',
      type: 'file',
      content: selectedFile.value.name,
      fileName: selectedFile.value.name,
      timestamp: new Date()
    })
    selectedFile.value = null
    messageCount.value++
  }

  // Handle text message
  if (inputMessage.value.trim()) {
    const userInput = inputMessage.value
    
    // Add user message to UI
    messages.value.push({
      id: Date.now().toString(),
      role: 'user',
      type: 'text',
      content: userInput,
      timestamp: new Date()
    })
    messageCount.value++
    
    inputMessage.value = ''
    await scrollToBottom()
    
    // Send to API if not at limit
    if (!limitReached.value) {
      isSending.value = true
      isTyping.value = true
      
      // Create temporary assistant message for streaming
      const assistantMessageId = Date.now().toString()
      messages.value.push({
        id: assistantMessageId,
        role: 'assistant',
        type: 'text',
        content: '',
        timestamp: new Date()
      })
      
      try {
        const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000'
        const response = await fetch(`${apiUrl}/api/v1/widget/${props.widgetId}/message`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            sessionId: sessionId.value,
            text: userInput,
            chatId: chatId.value || undefined
          })
        })
        
        if (!response.ok) {
          const error = await response.json().catch(() => ({}))
          throw new Error(error.error || `HTTP ${response.status}`)
        }

        // Legacy API responded with SSE; new endpoint returns complete response JSON
        const data = await response.json()

        if (data.error) {
          throw new Error(data.error)
        }

        if (data.chatId) {
          chatId.value = data.chatId
          const key = getChatStorageKey()
          if (key) {
            localStorage.setItem(key, data.chatId.toString())
          }
          if (!historyLoaded.value) {
            await loadConversationHistory()
          }
        }

        if (data.messageId && data.response) {
          const lastMessage = messages.value[messages.value.length - 1]
          if (lastMessage && lastMessage.id === assistantMessageId) {
            lastMessage.content = data.response
            isTyping.value = false
            scrollToBottom()
          }
        }

        isSending.value = false
      } catch (error) {
        console.error('Failed to send message:', error)
        // Remove the empty assistant message and show error
        const lastMessageIndex = messages.value.findIndex(m => m.id === assistantMessageId)
        if (lastMessageIndex !== -1) {
          messages.value.splice(lastMessageIndex, 1)
        }
        addBotMessage('Sorry, I encountered an error. Please try again.')
      } finally {
        isTyping.value = false
        isSending.value = false
      }
    }
  }
}

const addBotMessage = (text: string) => {
  messages.value.push({
    id: Date.now().toString(),
    role: 'assistant',
    type: 'text',
    content: text,
    timestamp: new Date()
  })
  
  if (!isOpen.value) {
    unreadCount.value++
  }
  
  scrollToBottom()
}

const scrollToBottom = async () => {
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

const formatTime = (date: Date): string => {
  return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

const getSessionStorageKey = () => `synaplan_widget_session_${props.widgetId}`
const getChatStorageKeyForSession = (id: string) => `synaplan_widget_chatid_${props.widgetId}_${id}`
const createSessionId = () => `sess_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`

const removeChatStorageKeys = (sessionToClear?: string) => {
  const legacyKey = `synaplan_widget_chatid_${props.widgetId}`
  const prefix = `synaplan_widget_chatid_${props.widgetId}_`

  if (sessionToClear) {
    localStorage.removeItem(`${prefix}${sessionToClear}`)
  } else {
    for (let idx = localStorage.length - 1; idx >= 0; idx -= 1) {
      const key = localStorage.key(idx)
      if (key && key.startsWith(prefix)) {
        localStorage.removeItem(key)
      }
    }
  }

  localStorage.removeItem(legacyKey)
}

const startNewConversation = () => {
  const storageKey = getSessionStorageKey()
  const previousSession = sessionId.value || localStorage.getItem(storageKey) || undefined
  const newSessionId = createSessionId()

  sessionId.value = newSessionId
  localStorage.setItem(storageKey, newSessionId)

  if (previousSession) {
    removeChatStorageKeys(previousSession)
  } else {
    removeChatStorageKeys()
  }

  chatId.value = null
  messages.value = []
  inputMessage.value = ''
  selectedFile.value = null
  fileSizeError.value = false
  messageCount.value = 0
  unreadCount.value = 0
  isTyping.value = false
  historyLoaded.value = false

  if (isOpen.value) {
    ensureAutoMessage()
  }

  window.dispatchEvent(new CustomEvent('synaplan-widget-session-changed', {
    detail: {
      widgetId: props.widgetId,
      sessionId: newSessionId
    }
  }))

  loadConversationHistory()
}

const handleOpenEvent = (event: Event) => {
  const detail = (event as CustomEvent).detail
  if (detail?.widgetId && detail.widgetId !== props.widgetId) {
    return
  }
  openChat()
}

const handleCloseEvent = (event: Event) => {
  const detail = (event as CustomEvent).detail
  if (detail?.widgetId && detail.widgetId !== props.widgetId) {
    return
  }
  closeChat()
}

const handleNewChatEvent = (event: Event) => {
  const detail = (event as CustomEvent).detail
  if (detail?.widgetId && detail.widgetId !== props.widgetId) {
    return
  }
  startNewConversation()
}

const normalizeServerMessage = (raw: any): Message => {
  let content = raw.text ?? ''
  if (typeof content === 'string') {
    try {
      const parsed = JSON.parse(content)
      if (parsed && typeof parsed === 'object') {
        if ('BTEXT' in parsed && typeof parsed.BTEXT === 'string') {
          content = parsed.BTEXT
        } else if ('content' in parsed && typeof parsed.content === 'string') {
          content = parsed.content
        }
      }
    } catch {
      // ignore parse errors
    }
  }

  const role = raw.direction === 'IN' ? 'user' : 'assistant'
  const timestampSeconds = typeof raw.timestamp === 'number' ? raw.timestamp : Date.now() / 1000

  return {
    id: String(raw.id ?? crypto.randomUUID()),
    role,
    type: 'text',
    content,
    timestamp: new Date(timestampSeconds * 1000)
  }
}

const loadConversationHistory = async () => {
  if (props.isPreview) {
    historyLoaded.value = true
    ensureAutoMessage()
    return
  }

  if (!props.widgetId) {
    historyLoaded.value = true
    return
  }

  if (!sessionId.value || historyLoaded.value || isLoadingHistory.value) {
    return
  }

  isLoadingHistory.value = true

  try {
    const baseUrl = resolveApiUrl()
    const params = new URLSearchParams({ sessionId: sessionId.value })
    const response = await fetch(`${baseUrl}/api/v1/widget/${props.widgetId}/history?${params.toString()}`)

    if (!response.ok) {
      throw new Error(`History request failed with status ${response.status}`)
    }

    const data = await response.json()
    if (data.success) {
      if (data.chatId) {
        chatId.value = data.chatId
      }

      const loadedMessages = Array.isArray(data.messages)
        ? data.messages.map((msg: any) => normalizeServerMessage(msg))
        : []

      if (loadedMessages.length > 0) {
        messages.value = loadedMessages
      }

      if (data.session && typeof data.session.messageCount === 'number') {
        messageCount.value = data.session.messageCount
      } else if (loadedMessages.length > 0) {
        messageCount.value = loadedMessages.filter((m: Message) => m.role === 'user').length
      }
    }
  } catch (error) {
    console.error('Failed to load widget history:', error)
  } finally {
    historyLoaded.value = true
    isLoadingHistory.value = false
    if (isOpen.value) {
      ensureAutoMessage()
    }
  }
}

// Load session ID from localStorage on mount
onMounted(() => {
  window.addEventListener('synaplan-widget-open', handleOpenEvent)
  window.addEventListener('synaplan-widget-close', handleCloseEvent)
  window.addEventListener('synaplan-widget-new-chat', handleNewChatEvent)

  const storageKey = getSessionStorageKey()
  let currentSessionId = localStorage.getItem(storageKey)
  if (!currentSessionId) {
    currentSessionId = createSessionId()
    localStorage.setItem(storageKey, currentSessionId)
  }
  sessionId.value = currentSessionId

  const sessionAwareKey = getChatStorageKeyForSession(currentSessionId)
  const legacyKey = `synaplan_widget_chatid_${props.widgetId}`
  const storedChatId = localStorage.getItem(sessionAwareKey) ?? localStorage.getItem(legacyKey)
  if (storedChatId) {
    chatId.value = parseInt(storedChatId, 10)
    if (!localStorage.getItem(sessionAwareKey)) {
      localStorage.setItem(sessionAwareKey, storedChatId)
    }
    if (localStorage.getItem(legacyKey)) {
      localStorage.removeItem(legacyKey)
    }
  }

  loadConversationHistory()
})

onBeforeUnmount(() => {
  window.removeEventListener('synaplan-widget-open', handleOpenEvent)
  window.removeEventListener('synaplan-widget-close', handleCloseEvent)
  window.removeEventListener('synaplan-widget-new-chat', handleNewChatEvent)
})

const getChatStorageKey = () => {
  if (!sessionId.value) return null
  return getChatStorageKeyForSession(sessionId.value)
}

// Save chatId to localStorage when it changes
watch(chatId, (newChatId) => {
  if (!newChatId) return
  const key = getChatStorageKey()
  if (key) {
    localStorage.setItem(key, newChatId.toString())
  }
})

// Auto-open
if (props.autoOpen) {
  setTimeout(() => {
    openChat()
  }, 3000)
}

watch(isOpen, (newVal) => {
  if (newVal) {
    scrollToBottom()
  }
})
</script>

