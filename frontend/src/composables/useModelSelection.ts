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
 * Priority:
 * 1. Backend againData (contains eligible models for specific capability/topic)
 * 2. Fallback to all available CHAT models from config store
 */
export function useModelSelection(
  againData?: ComputedRef<AgainData | null | undefined>
) {
  const aiConfigStore = useAiConfigStore()

  /**
   * Get available model options for selection
   * Prioritizes againData eligible models, falls back to CHAT models from store
   */
  const modelOptions = computed((): ModelOption[] => {
    // Priority 1: Use backend againData if available (best - filtered by capability/topic)
    if (againData?.value?.eligible && againData.value.eligible.length > 0) {
      return againData.value.eligible.map((model: any) => ({
        provider: model.service,
        model: model.name,
        label: model.label || model.name,
        id: model.id,
        quality: model.quality,
        rating: model.rating,
        description: model.description
      }))
    }

    // Priority 2: Use all available CHAT models from config store
    const chatModels = aiConfigStore.models['CHAT'] || []
    if (chatModels.length > 0) {
      return chatModels.map((model: AIModel) => ({
        provider: model.service,
        model: model.name,
        label: model.name,
        id: model.id,
        description: model.description
      }))
    }

    // No models available
    return []
  })

  /**
   * Get the predicted/recommended next model
   * Prioritizes backend prediction, falls back to first available model
   */
  const predictedModel = computed((): ModelOption | null => {
    // Priority 1: Use backend predicted model (Round-Robin, excluding current)
    if (againData?.value?.predictedNext) {
      const predicted = againData.value.predictedNext
      return {
        provider: predicted.service,
        model: predicted.name,
        label: predicted.label || predicted.name,
        id: predicted.id,
        quality: predicted.quality,
        rating: predicted.rating,
        description: predicted.description
      }
    }

    // Priority 2: First available model
    return modelOptions.value[0] || null
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

  /**
   * Get current model ID from againData
   */
  const currentModelId = computed(() => againData?.value?.current_model_id ?? null)

  return {
    modelOptions,
    predictedModel,
    formatModelLabel,
    hasModels,
    currentModelId
  }
}

