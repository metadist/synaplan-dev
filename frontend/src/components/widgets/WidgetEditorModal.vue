<template>
  <!-- Fullscreen Modal Overlay -->
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
    <div class="surface-card rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto shadow-2xl">
      <!-- Header -->
      <div class="sticky top-0 surface-card border-b border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:cog-6-tooth" class="w-6 h-6 text-[var(--brand)]" />
            {{ isEdit ? $t('widgets.editWidget') : $t('widgets.createWidget') }}
          </h2>
          <p class="text-sm txt-secondary mt-1">{{ isEdit ? widget?.name : $t('widgets.createDescription') }}</p>
        </div>
        <button
          @click="$emit('close')"
          class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center"
          :aria-label="$t('common.close')"
        >
          <Icon icon="heroicons:x-mark" class="w-6 h-6 txt-secondary" />
        </button>
      </div>

      <!-- Content -->
      <div class="p-6 space-y-6">
        <!-- Basic Settings -->
        <div class="space-y-4">
          <h3 class="font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:document-text" class="w-5 h-5" />
            {{ $t('widgets.basicSettings') }}
          </h3>

          <!-- Widget Name -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.widgetName') }}
            </label>
            <input
              v-model="formData.name"
              type="text"
              :placeholder="$t('widgets.widgetNamePlaceholder')"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            />
          </div>

          <!-- Task Prompt Selection (nur bei Create) -->
          <div v-if="!isEdit">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.taskPrompt') }}
            </label>
            <select
              v-model="formData.taskPromptTopic"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            >
              <option value="">{{ $t('widgets.selectTaskPrompt') }}</option>
              <option
                v-for="prompt in taskPrompts"
                :key="prompt.topic"
                :value="prompt.topic"
              >
                {{ prompt.name }}
              </option>
            </select>
            <p class="text-xs txt-secondary mt-1.5">
              {{ $t('widgets.taskPromptHelp') }}
            </p>
          </div>

          <!-- Status (nur bei Edit) -->
          <div v-if="isEdit">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.status') }}
            </label>
            <div class="flex items-center gap-4">
              <label class="flex items-center gap-2 cursor-pointer">
                <input
                  v-model="formData.status"
                  type="radio"
                  value="active"
                  class="w-4 h-4 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
                />
                <span class="text-sm txt-primary">{{ $t('widgets.active') }}</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer">
                <input
                  v-model="formData.status"
                  type="radio"
                  value="inactive"
                  class="w-4 h-4 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
                />
                <span class="text-sm txt-primary">{{ $t('widgets.inactive') }}</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Appearance Settings -->
        <div class="space-y-4">
          <h3 class="font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:paint-brush" class="w-5 h-5" />
            {{ $t('widgets.appearance') }}
          </h3>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Position -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.position') }}
              </label>
              <select
                v-model="formData.config.position"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              >
                <option value="bottom-right">{{ $t('widgets.bottomRight') }}</option>
                <option value="bottom-left">{{ $t('widgets.bottomLeft') }}</option>
                <option value="top-right">{{ $t('widgets.topRight') }}</option>
                <option value="top-left">{{ $t('widgets.topLeft') }}</option>
              </select>
            </div>

            <!-- Default Theme -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.defaultTheme') }}
              </label>
              <select
                v-model="formData.config.defaultTheme"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              >
                <option value="light">{{ $t('widgets.light') }}</option>
                <option value="dark">{{ $t('widgets.dark') }}</option>
              </select>
            </div>

            <!-- Primary Color -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.primaryColor') }}
              </label>
              <input
                v-model="formData.config.primaryColor"
                type="color"
                class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
              />
            </div>

            <!-- Icon Color -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.iconColor') }}
              </label>
              <input
                v-model="formData.config.iconColor"
                type="color"
                class="w-full h-12 rounded-lg border border-light-border/30 dark:border-dark-border/20 cursor-pointer"
              />
            </div>
          </div>
        </div>

        <!-- Behavior Settings -->
        <div class="space-y-4">
          <h3 class="font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:adjustments-horizontal" class="w-5 h-5" />
            {{ $t('widgets.behavior') }}
          </h3>

          <!-- Auto Open -->
          <div class="flex items-center justify-between p-4 surface-chip rounded-lg">
            <div>
              <p class="font-medium txt-primary">{{ $t('widgets.autoOpen') }}</p>
              <p class="text-xs txt-secondary mt-1">{{ $t('widgets.autoOpenHelp') }}</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input
                v-model="formData.config.autoOpen"
                type="checkbox"
                class="sr-only peer"
              />
              <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[var(--brand)]/20 dark:peer-focus:ring-[var(--brand)]/30 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-[var(--brand)]"></div>
            </label>
          </div>

          <!-- Auto Message -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.autoMessage') }}
            </label>
            <textarea
              v-model="formData.config.autoMessage"
              rows="2"
              :placeholder="$t('widgets.autoMessagePlaceholder')"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
            />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Message Limit -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.messageLimit') }}
              </label>
              <input
                v-model.number="formData.config.messageLimit"
                type="number"
                min="1"
                max="100"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              />
              <p class="text-xs txt-secondary mt-1.5">{{ $t('widgets.messageLimitHelp') }}</p>
            </div>

            <!-- Max File Size -->
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('widgets.maxFileSize') }} (MB)
              </label>
              <input
                v-model.number="formData.config.maxFileSize"
                type="number"
                min="1"
                max="50"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="sticky bottom-0 surface-card border-t border-light-border/30 dark:border-dark-border/20 px-6 py-4 flex items-center justify-end gap-3">
        <button
          @click="$emit('close')"
          class="px-6 py-2.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-primary font-medium"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          @click="handleSave"
          :disabled="!canSave"
          class="px-6 py-2.5 rounded-lg bg-[var(--brand)] text-white hover:bg-[var(--brand)]/90 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ isEdit ? $t('common.save') : $t('common.create') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Icon } from '@iconify/vue'
import type { Widget, WidgetConfig } from '@/services/api/widgetsApi'
import * as promptsApi from '@/services/api/promptsApi'

interface Props {
  widget?: Widget | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
  close: []
  save: [data: any]
}>()

const isEdit = computed(() => !!props.widget)

const taskPrompts = ref<any[]>([])

const formData = ref<{
  name: string
  taskPromptTopic: string
  status: 'active' | 'inactive'
  config: WidgetConfig
}>({
  name: props.widget?.name || '',
  taskPromptTopic: props.widget?.taskPromptTopic || '',
  status: props.widget?.status || 'active',
  config: {
    position: props.widget?.config.position || 'bottom-right',
    primaryColor: props.widget?.config.primaryColor || '#007bff',
    iconColor: props.widget?.config.iconColor || '#ffffff',
    defaultTheme: props.widget?.config.defaultTheme || 'light',
    autoOpen: props.widget?.config.autoOpen || false,
    autoMessage: props.widget?.config.autoMessage || 'Hello! How can I help you today?',
    messageLimit: props.widget?.config.messageLimit || 50,
    maxFileSize: props.widget?.config.maxFileSize || 10
  }
})

const canSave = computed(() => {
  if (isEdit.value) {
    return formData.value.name.trim() !== ''
  }
  return formData.value.name.trim() !== '' && formData.value.taskPromptTopic !== ''
})

/**
 * Load task prompts
 */
const loadTaskPrompts = async () => {
  try {
    const prompts = await promptsApi.listPrompts()
    taskPrompts.value = prompts
  } catch (error) {
    console.error('Failed to load task prompts:', error)
  }
}

/**
 * Handle save
 */
const handleSave = () => {
  if (!canSave.value) return

  const data = isEdit.value
    ? {
        name: formData.value.name,
        config: formData.value.config,
        status: formData.value.status
      }
    : {
        name: formData.value.name,
        taskPromptTopic: formData.value.taskPromptTopic,
        config: formData.value.config
      }

  emit('save', data)
}

onMounted(() => {
  if (!isEdit.value) {
    loadTaskPrompts()
  }
})
</script>

