import { computed, type ComputedRef } from 'vue'
import { useAiConfigStore, type AIModel } from '@/stores/aiConfig'

export interface ModelOption {
  provider: string
  model: string
  label: string
  id?: number
  quality?: number
  rating?: number
  description?: string
}

export interface AgainData {
  eligible?: Array<{
    id: number
    service: string
    name: string
    providerId: number
    description?: string
    quality?: number
    rating?: number
    tag?: string
    label?: string
  }>
  predictedNext?: {
    id: number
    service: string
    name: string
    providerId: number
    description?: string
    quality?: number
    rating?: number
    tag?: string
    label?: string
  }
  current_model_id?: number | null
  tag?: string
}

/**
 * Composable for model selection logic
 * Provides unified model selection for Again functionality
 * 
 * Now generates againData in frontend based on message files and available models:
 * - For images: Uses TEXT2PIC models
 * - For videos: Uses TEXT2VID models  
 * - For audio: Uses TEXT2SOUND models
 * - Otherwise: Uses CHAT models
 * 
 * Round-Robin: Recommends next model in BRANKING-sorted list (not the current one)
 */
export function useModelSelection(
  againData?: ComputedRef<AgainData | null | undefined>,
  files?: ComputedRef<any[] | undefined>,
  currentProvider?: ComputedRef<string | undefined>,
  currentModelName?: ComputedRef<string | undefined>
) {
  const aiConfigStore = useAiConfigStore()

  /**
   * Detect media type from message files
   */
  const mediaType = computed((): 'image' | 'video' | 'audio' | 'chat' => {
    if (!files?.value || files.value.length === 0) {
      return 'chat'
    }

    const file = files.value[0]
    if (file.type?.startsWith('image/')) return 'image'
    if (file.type?.startsWith('video/')) return 'video'
    if (file.type?.startsWith('audio/')) return 'audio'
    
    return 'chat'
  })

  /**
   * Get the appropriate model tag based on media type
   */
  const modelTag = computed((): string => {
    switch (mediaType.value) {
      case 'image': return 'TEXT2PIC'
      case 'video': return 'TEXT2VID'
      case 'audio': return 'TEXT2SOUND'
      default: return 'CHAT'
    }
  })

  /**
   * Get current model ID by finding model in store based on provider + name
   * Searches across ALL tags to find the current model
   */
  const currentModelId = computed((): number | null => {
    if (!currentProvider?.value || !currentModelName?.value) {
      return null
    }

    // Search across ALL model tags to find current model
    const allTags = Object.keys(aiConfigStore.models)
    for (const tag of allTags) {
      const models = aiConfigStore.models[tag] || []
      const found = models.find((m: AIModel) => 
        m.service.toLowerCase() === currentProvider.value?.toLowerCase() &&
        m.name.toLowerCase() === currentModelName.value?.toLowerCase()
      )
      if (found) {
        return found.id
      }
    }

    return null
  })

  /**
   * Get available model options for selection
   * Uses models from store based on media type (chat/image/video/audio)
   * Models are sorted by BRANKING (rating) - highest first
   */
  const modelOptions = computed((): ModelOption[] => {
    const tag = modelTag.value
    const models = aiConfigStore.models[tag] || []
    
    console.log('🔍 useModelSelection:', {
      mediaType: mediaType.value,
      modelTag: tag,
      filesCount: files?.value?.length || 0,
      availableModelsForTag: models.length
    })
    
    if (models.length === 0) {
      console.warn('⚠️ No models available for tag:', tag)
      return []
    }

    return models
      .sort((a: AIModel, b: AIModel) => (b.rating || 0) - (a.rating || 0)) // Sort by BRANKING descending
      .map((model: AIModel) => ({
        provider: model.service,
        model: model.name,
        label: model.name,
        id: model.id,
        quality: model.quality,
        rating: model.rating,
        description: model.description
      }))
  })

  /**
   * Get the predicted/recommended next model using Round-Robin
   * - Finds current model in sorted list
   * - Returns NEXT model in list (wraps around to start)
   * - Ensures we don't always recommend the same model
   */
  const predictedModel = computed((): ModelOption | null => {
    const options = modelOptions.value
    if (options.length === 0) return null
    if (options.length === 1) return options[0] // Only one model available
    
    // Try to find current model in the filtered options for this media type
    const currentId = currentModelId.value
    if (currentId) {
      const currentIndex = options.findIndex((m: ModelOption) => m.id === currentId)
      if (currentIndex !== -1) {
        // Found current model - return NEXT one (Round-Robin)
        const nextIndex = (currentIndex + 1) % options.length
        console.log('🔄 Round-Robin: Current index', currentIndex, '→ Next index', nextIndex)
        return options[nextIndex]
      }
    }
    
    // Fallback: Return highest-rated model (first in sorted list)
    console.log('⭐ Fallback: Using highest-rated model')
    return options[0]
  })

  /**
   * Format model for display
   */
  const formatModelLabel = (model: ModelOption): string => {
    return model.label || model.model
  }

  /**
   * Check if model selection is available
   */
  const hasModels = computed(() => modelOptions.value.length > 0)

  return {
    modelOptions,
    predictedModel,
    formatModelLabel,
    hasModels,
    currentModelId,
    mediaType
  }
}

