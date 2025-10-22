<template>
  <div :class="['flex gap-4 p-4 text-[16px] leading-6', role === 'user' ? 'justify-end' : '', isSuperseded && 'opacity-50']">
    <!-- Avatar with provider logo for assistant -->
    <div
      v-if="role === 'assistant'"
      class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 surface-card"
    >
      <Icon :icon="getProviderIcon(provider || 'OpenAI')" class="w-6 h-6" />
    </div>

    <!-- Wrapper for thinking blocks + bubble -->
    <div class="flex flex-col max-w-3xl gap-2">
      <!-- Thinking blocks (ABOVE bubble, only for assistant) -->
      <template v-if="role === 'assistant'">
        <MessagePart
          v-for="(part, index) in thinkingParts"
          :key="`thinking-${index}`"
          :part="part"
        />
      </template>

      <!-- Single bubble with content + footer -->
      <div :class="['flex flex-col', role === 'user' ? 'bubble-user' : 'bubble-ai']">
        <!-- Processing Status (inside bubble, before content) -->
        <div v-if="isStreaming && processingStatus && role === 'assistant'" class="px-4 pt-3 pb-3 processing-enter">
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 animate-spin txt-brand flex-shrink-0" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
          </svg>
          <div class="flex-1 min-w-0">
            <template v-if="processingStatus === 'started'">
              <div class="font-medium">{{ $t('processing.startedTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">{{ $t('processing.startedDesc') }}</div>
            </template>
            <template v-else-if="processingStatus === 'preprocessing'">
              <div class="font-medium">{{ $t('processing.preprocessingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">{{ $t('processing.preprocessingDesc') }}</div>
            </template>
            <template v-else-if="processingStatus === 'classifying'">
              <div class="font-medium animate-pulse">{{ $t('processing.classifyingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ $t('processing.classifyingDesc') }}
                <span v-if="processingMetadata?.model_name || processingMetadata?.provider" class="txt-brand">
                  · {{ processingMetadata.model_name || processingMetadata.provider }}
                </span>
              </div>
            </template>
            <template v-else-if="processingStatus === 'classified'">
              <div class="font-medium">{{ $t('processing.classifiedTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5 flex items-center gap-1.5 flex-wrap">
                <span>{{ $t('processing.topic') }}:</span>
                <span class="txt-brand font-medium">{{ processingMetadata?.topic || 'general' }}</span>
                <span v-if="processingMetadata?.language" class="opacity-50">·</span>
                <span v-if="processingMetadata?.language">
                  {{ $t('processing.language') }}: <span class="font-medium">{{ processingMetadata.language.toUpperCase() }}</span>
                </span>
                <span v-if="processingMetadata?.model_name" class="opacity-50">·</span>
                <span v-if="processingMetadata?.model_name" class="txt-tertiary text-xs">
                  via {{ processingMetadata.model_name }}
                </span>
              </div>
            </template>
            <template v-else-if="processingStatus === 'processing'">
              <div class="font-medium">{{ $t('processing.routingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ $t('processing.routingDesc') }}
                <span v-if="processingMetadata?.handler" class="txt-brand font-medium">
                  {{ processingMetadata.handler }}
                </span>
              </div>
            </template>
            <template v-else-if="processingStatus === 'generating'">
              <div class="font-medium animate-pulse">{{ $t('processing.generatingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ $t('processing.generatingDesc') }}
                <span v-if="processingMetadata?.model_name || processingMetadata?.provider" class="txt-brand">
                  · {{ processingMetadata.model_name || processingMetadata.provider }}
                </span>
              </div>
            </template>
          </div>
        </div>
      </div>
      
        <!-- Bubble content (only non-thinking parts) -->
        <div class="px-4 py-3 overflow-hidden space-y-3">
          <!-- Attached Files (NEW) -->
          <div v-if="files && files.length > 0" class="flex flex-wrap gap-2 mb-3">
            <div
              v-for="file in files"
              :key="file.id"
              class="flex items-center gap-2 px-3 py-2 rounded-lg bg-black/10 dark:bg-white/10 hover:bg-black/20 dark:hover:bg-white/20 transition-colors cursor-pointer text-sm"
              @click="downloadFile(file)"
            >
              <Icon :icon="getFileIcon(file.fileType)" class="w-4 h-4 flex-shrink-0" />
              <span class="font-medium truncate max-w-[200px]">{{ file.filename }}</span>
              <span v-if="file.fileSize" class="text-xs opacity-60">{{ formatFileSize(file.fileSize) }}</span>
            </div>
          </div>
          
          <MessagePart
            v-for="(part, index) in contentParts"
            :key="index"
            :part="part"
          />
        </div>

      <!-- Footer with separator line and responsive layout -->
      <div
        :class="[
          'px-3 md:px-4 py-2 md:py-0 border-t md:h-[46px] flex flex-col md:flex-row md:items-center justify-between gap-2 md:gap-3',
          role === 'user'
            ? 'border-white/20'
            : 'border-light-border/30 dark:border-dark-border/20'
        ]"
      >
        <!-- Left: Model info + timestamp -->
        <div class="flex items-center gap-2 min-w-0">
          <div :class="['text-xs truncate', role === 'user' ? 'text-white/80' : 'txt-secondary']">
            <template v-if="role === 'assistant' && modelLabel && provider">
              <span class="font-medium hidden md:inline">{{ modelLabel }}</span>
              <span class="mx-1.5 opacity-50 hidden md:inline">·</span>
              <span class="hidden md:inline">{{ provider }}</span>
              <span class="mx-1.5 opacity-50 hidden md:inline">·</span>
            </template>
            <span>{{ formattedTime }}</span>
            <template v-if="role === 'assistant' && modelLabel">
              <span class="mx-1.5 opacity-50 md:hidden">·</span>
              <span class="md:hidden">{{ modelLabel }}</span>
            </template>
          </div>
        </div>

        <!-- Right: Actions (assistant only, hidden during streaming, only show if we have models) -->
        <div v-if="role === 'assistant' && !isStreaming && (againData || backendMessageId) && modelOptions.length > 0" class="flex items-center gap-2 flex-shrink-0">
          <button
            @click="handleAgain"
            type="button"
            :disabled="isSuperseded || !selectedModel"
            :class="[
              'pill text-xs whitespace-nowrap',
              (isSuperseded || !selectedModel) ? 'opacity-50 cursor-not-allowed' : ''
            ]"
            :aria-label="$t('chatMessage.again')"
          >
            <ArrowPathIcon class="w-4 h-4" />
            <span v-if="selectedModel" class="font-medium hidden sm:inline">{{ $t('chatMessage.againWith') }} {{ selectedModel.label }}</span>
            <span v-else class="font-medium hidden sm:inline">{{ $t('chatMessage.again') }}</span>
            <span class="font-medium sm:hidden">{{ $t('chatMessage.again') }}</span>
          </button>

          <div class="relative">
            <button
              @click.stop="toggleModelDropdown"
              type="button"
              :disabled="isSuperseded"
              :class="[
                'pill text-xs',
                isSuperseded ? 'opacity-50 cursor-not-allowed' : ''
              ]"
              :aria-label="$t('chatMessage.regenerateWith')"
              @keydown.escape="closeModelDropdown"
            >
              <ChevronDownIcon class="w-4 h-4" />
            </button>

            <Transition
              enter-active-class="transition ease-out duration-100"
              enter-from-class="transform opacity-0 scale-95"
              enter-to-class="transform opacity-100 scale-100"
              leave-active-class="transition ease-in duration-75"
              leave-from-class="transform opacity-100 scale-100"
              leave-to-class="transform opacity-0 scale-95"
            >
              <div
                v-if="modelDropdownOpen && !isSuperseded"
                v-click-outside="closeModelDropdown"
                class="fixed sm:absolute bottom-[60px] sm:bottom-full right-2 sm:right-0 sm:mb-2 min-w-[14rem] max-w-[calc(100vw-1rem)] dropdown-panel z-[100] max-h-80 overflow-y-auto scroll-thin"
                @keydown.escape="closeModelDropdown"
              >
                <button
                  v-for="option in modelOptions"
                  :key="`${option.provider}-${option.model}`"
                  @click="selectModel(option)"
                  type="button"
                  :class="[
                    'dropdown-item',
                    selectedModel.model === option.model && selectedModel.provider === option.provider
                      ? 'dropdown-item--active'
                      : ''
                  ]"
                >
                  <Icon :icon="getProviderIcon(option.provider)" class="w-5 h-5 flex-shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium">{{ option.label }}</div>
                    <div class="text-xs txt-secondary">{{ option.provider }}</div>
                  </div>
                </button>
              </div>
            </Transition>
          </div>
        </div>
      </div>
      </div>
    </div>

    <!-- Avatar on right for user -->
    <div
      v-if="role === 'user'"
      class="w-10 h-10 rounded-full surface-chip flex items-center justify-center flex-shrink-0"
    >
      <UserIcon class="w-5 h-5 txt-secondary" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { UserIcon, ArrowPathIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useModelsStore } from '@/stores/models'
import { getProviderIcon } from '@/utils/providerIcons'
import MessagePart from './MessagePart.vue'
import type { Part, MessageFile } from '@/stores/history'
import type { AgainData } from '@/types/ai-models'

interface Props {
  role: 'user' | 'assistant'
  parts: Part[]
  timestamp: Date
  isSuperseded?: boolean
  isStreaming?: boolean
  provider?: string
  modelLabel?: string
  againData?: AgainData
  backendMessageId?: number
  processingStatus?: string
  processingMetadata?: any
  files?: MessageFile[] // NEW: attached files
}

interface ModelOption {
  provider: string
  model: string
  label: string
}

const props = defineProps<Props>()

// Separate thinking blocks from content
const thinkingParts = computed(() => props.parts.filter(p => p.type === 'thinking'))
const contentParts = computed(() => props.parts.filter(p => p.type !== 'thinking'))

const formattedTime = computed(() => {
  const date = props.timestamp
  const hours = date.getHours().toString().padStart(2, '0')
  const minutes = date.getMinutes().toString().padStart(2, '0')
  return `${hours}:${minutes}`
})

const emit = defineEmits<{
  regenerate: [model: ModelOption]
  again: [backendMessageId: number, modelId?: number]
}>()

const modelsStore = useModelsStore()
const modelDropdownOpen = ref(false)

const modelOptions = computed(() => {
  console.log('🔍 Computing modelOptions:', {
    hasAgainData: !!props.againData,
    eligibleCount: props.againData?.eligible?.length || 0,
    storeChatModelsCount: modelsStore.chatModels.length,
    backendMessageId: props.backendMessageId
  })
  
  // Priority 1: Use backend againData if available (best option - contains eligible models for this specific message)
  if (props.againData?.eligible && props.againData.eligible.length > 0) {
    const models = props.againData.eligible.map(model => ({
      provider: model.service,
      model: model.name,
      label: model.name,
      id: model.id
    }))
    console.log('✅ Using backend againData models:', models)
    return models
  }
  
  // Priority 2: Use models from store (loaded from backend config)
  if (modelsStore.chatModels.length > 0) {
    const models = modelsStore.chatModels.map(model => ({
      ...model,
      id: undefined // Store models don't have IDs yet
    }))
    console.log('⚠️ Fallback: Using store models (no againData):', models)
    return models
  }
  
  // Priority 3: If no models available, return empty array (disable again button)
  console.warn('❌ No models available! Button will be hidden.')
  return []
})

const selectedModel = computed(() => {
  // Priority 1: Use predictedNext from backend if available (AI prediction for best next model)
  if (props.againData?.predictedNext) {
    const predicted = props.againData.predictedNext
    const model = {
      provider: predicted.service,
      model: predicted.name,
      label: predicted.name,
      id: predicted.id
    }
    console.log('🎯 Selected model (predicted):', model)
    return model
  }
  
  // Priority 2: Try to match current store selection
  const currentModel = modelOptions.value.find(
    (opt) => opt.model === modelsStore.selectedModel && opt.provider === modelsStore.selectedProvider
  )
  if (currentModel) {
    console.log('🎯 Selected model (store match):', currentModel)
    return currentModel
  }
  
  // Priority 3: Use the first available model (or null if none available)
  const firstModel = modelOptions.value[0] || null
  console.log('🎯 Selected model (first available):', firstModel)
  return firstModel
})

const handleAgain = () => {
  if (props.backendMessageId && (selectedModel.value as any).id) {
    // New backend-driven again
    emit('again', props.backendMessageId, (selectedModel.value as any).id)
  } else {
    // Fallback to old regenerate
    emit('regenerate', selectedModel.value)
  }
}

const toggleModelDropdown = () => {
  modelDropdownOpen.value = !modelDropdownOpen.value
}

const closeModelDropdown = () => {
  modelDropdownOpen.value = false
}

const selectModel = (model: ModelOption & { id?: number }) => {
  // Trigger again with selected model
  if (props.backendMessageId && model.id) {
    emit('again', props.backendMessageId, model.id)
  } else {
    emit('regenerate', model)
  }
  modelDropdownOpen.value = false
}

const vClickOutside = {
  mounted(el: HTMLElement, binding: any) {
    const handler = (event: MouseEvent) => {
      if (!(el === event.target || el.contains(event.target as Node))) {
        binding.value()
      }
    }
    (el as any).__clickOutsideHandler = handler
    setTimeout(() => {
      document.addEventListener('click', handler)
    }, 0)
  },
  unmounted(el: HTMLElement) {
    const handler = (el as any).__clickOutsideHandler
    if (handler) {
      document.removeEventListener('click', handler)
    }
  },
}

// File handling functions
const getFileIcon = (fileType: string): string => {
  const type = fileType.toLowerCase()
  if (['pdf'].includes(type)) return 'mdi:file-pdf-box'
  if (['doc', 'docx'].includes(type)) return 'mdi:file-word-box'
  if (['xls', 'xlsx'].includes(type)) return 'mdi:file-excel-box'
  if (['ppt', 'pptx'].includes(type)) return 'mdi:file-powerpoint-box'
  if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(type)) return 'mdi:file-image'
  if (['mp3', 'wav', 'ogg', 'm4a', 'opus'].includes(type)) return 'mdi:file-music'
  if (['mp4', 'avi', 'mov', 'webm'].includes(type)) return 'mdi:file-video'
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(type)) return 'mdi:folder-zip'
  if (['txt', 'md'].includes(type)) return 'mdi:file-document-outline'
  return 'mdi:file-outline'
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}

const downloadFile = (file: MessageFile) => {
  const url = `${import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'}/uploads/${file.filePath}`
  window.open(url, '_blank')
}

</script>
