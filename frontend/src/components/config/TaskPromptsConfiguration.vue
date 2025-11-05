<template>
  <div class="space-y-6">
    <!-- Loading State -->
    <div v-if="loading && prompts.length === 0" class="surface-card p-8 text-center">
      <div class="inline-block animate-spin rounded-full h-10 w-10 border-b-2 border-[var(--brand)]"></div>
      <p class="mt-4 txt-secondary text-sm">{{ $t('common.loading') }}...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error && prompts.length === 0" class="surface-card p-8">
      <div class="text-center text-red-600 dark:text-red-400">
        <Icon icon="heroicons:exclamation-triangle" class="w-12 h-12 mx-auto mb-3" />
        <p class="font-semibold mb-2">{{ $t('common.error') }}</p>
        <p class="text-sm txt-secondary mb-4">{{ error }}</p>
        <button
          @click="loadPrompts"
          class="px-5 py-2.5 rounded-lg border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors font-medium"
        >
          <Icon icon="heroicons:arrow-path" class="w-4 h-4 inline mr-2" />
          {{ $t('common.retry') }}
        </button>
      </div>
    </div>

    <!-- Main Content -->
    <template v-else-if="currentPrompt">
      <!-- Header Card -->
    <div class="surface-card p-6">
        <div class="flex items-start justify-between mb-6">
          <div>
            <h2 class="text-2xl font-semibold txt-primary flex items-center gap-3">
              <Icon icon="heroicons:document-text" class="w-7 h-7 text-[var(--brand)]" />
        {{ $t('config.taskPrompts.title') }}
      </h2>
            <p class="txt-secondary text-sm mt-1">{{ $t('config.taskPrompts.subtitle') }}</p>
          </div>
        </div>

        <!-- Prompt Selector -->
        <div>
          <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
            <Icon icon="heroicons:list-bullet" class="w-4 h-4" />
            {{ $t('config.taskPrompts.selectPrompt') }}
          </label>
          <select
            v-model="selectedPromptId"
            @change="onPromptSelect"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
          >
            <option
              v-for="prompt in prompts"
              :key="prompt.id"
              :value="prompt.id"
            >
              {{ prompt.name }}
            </option>
          </select>
          <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
            <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
            {{ $t('config.taskPrompts.selectPromptHelp') }}
          </p>
        </div>
      </div>

      <!-- Prompt Details Card -->
      <div class="surface-card p-6">
        <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
          <Icon icon="heroicons:cog-6-tooth" class="w-5 h-5 text-[var(--brand)]" />
          {{ $t('config.taskPrompts.promptDetails') }}
        </h3>

        <div class="space-y-5">
          <!-- Rules / Description -->
        <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:clipboard-document-list" class="w-4 h-4" />
            {{ $t('config.taskPrompts.rulesForSelection') }}
          </label>
          <textarea
              v-model="formData.rules"
            rows="3"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
            :placeholder="$t('config.taskPrompts.rulesHelp')"
          />
        </div>

          <!-- AI Model Selection -->
        <div>
            <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="heroicons:cpu-chip" class="w-4 h-4" />
            {{ $t('config.taskPrompts.aiModel') }}
          </label>
          <select
              v-model="formData.aiModel"
            class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option value="AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]">
                ✨ {{ $t('config.taskPrompts.automated') }}
            </option>
              
              <!-- Grouped Models by Capability -->
              <template v-if="!loadingModels && groupedModels.length > 0">
                <optgroup
                  v-for="group in groupedModels"
                  :key="group.capability"
                  :label="group.label"
                >
            <option
                    v-for="model in group.models"
              :key="model.id"
              :value="`${model.name} (${model.service})`"
            >
              {{ model.name }} ({{ model.service }})
                    <template v-if="model.rating">⭐ {{ model.rating.toFixed(1) }}</template>
            </option>
                </optgroup>
              </template>
              
              <!-- Loading state -->
              <option v-if="loadingModels" disabled>Loading models...</option>
          </select>
            <p class="text-xs txt-secondary mt-1.5 flex items-center gap-1">
              <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
            {{ $t('config.taskPrompts.aiModelHelp') }}
          </p>
        </div>

          <!-- Available Tools -->
        <div>
            <label class="block text-sm font-semibold txt-primary mb-3 flex items-center gap-2">
              <Icon icon="heroicons:wrench-screwdriver" class="w-4 h-4" />
            {{ $t('config.taskPrompts.availableTools') }}
          </label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label
                v-for="tool in availableTools"
              :key="tool.value"
                class="flex items-center gap-3 p-3 rounded-lg surface-chip cursor-pointer hover:bg-[var(--brand)]/5 transition-colors"
            >
              <input
                  v-model="formData.availableTools"
                type="checkbox"
                :value="tool.value"
                class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
              />
                <Icon :icon="tool.icon" class="w-5 h-5 txt-secondary" />
              <span class="text-sm txt-primary">{{ tool.label }}</span>
            </label>
          </div>
        </div>
      </div>
    </div>

      <!-- Prompt Content Card -->
    <div class="surface-card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:code-bracket" class="w-5 h-5 text-[var(--brand)]" />
        {{ $t('config.taskPrompts.promptContent') }}
      </h3>

          <!-- Markdown Toolbar -->
          <div class="flex items-center gap-1 p-1 surface-chip rounded-lg">
          <button
              v-for="tool in markdownTools"
              :key="tool.label"
              @click="insertMarkdown(tool.before, tool.after)"
              class="p-2 rounded hover:bg-[var(--brand)]/10 txt-secondary hover:txt-primary transition-colors"
              :title="tool.label"
            >
              <Icon :icon="tool.icon" class="w-4 h-4" />
          </button>
          </div>
        </div>

        <textarea
          ref="contentTextarea"
          v-model="formData.content"
          rows="16"
          class="w-full px-4 py-3 surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none font-mono"
          :placeholder="$t('config.taskPrompts.contentPlaceholder')"
        />
        
        <p class="text-xs txt-secondary mt-2 flex items-center gap-1">
          <Icon icon="heroicons:information-circle" class="w-3.5 h-3.5" />
          {{ $t('config.taskPrompts.contentHelp') }}
        </p>
      </div>

      <!-- Knowledge Base Files Card -->
      <div class="surface-card p-6">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="text-lg font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:document-text" class="w-5 h-5 text-[var(--brand)]" />
              Knowledge Base Files
            </h3>
            <p class="text-xs txt-secondary mt-1">
              Upload files or link existing files that provide context for this task prompt
            </p>
          </div>
        </div>

        <!-- Tabs: Upload New | Link Existing -->
        <!-- Upload Files Button (redirect to File Manager) -->
        <div class="mb-4">
          <router-link
            to="/files"
            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20 transition-colors text-sm font-medium"
          >
            <Icon icon="heroicons:cloud-arrow-up" class="w-5 h-5" />
            Upload Files in File Manager
            <Icon icon="heroicons:arrow-right" class="w-4 h-4" />
          </router-link>
        </div>

        <!-- Linked Files for this Prompt -->
        <div v-if="promptFiles.length > 0" class="mb-4">
          <h4 class="text-sm font-semibold txt-primary mb-2">Linked Files ({{ promptFiles.length }})</h4>
          <div class="space-y-2 max-h-[300px] overflow-y-auto">
            <div
              v-for="file in promptFiles"
              :key="file.messageId"
              class="flex items-center justify-between p-3 surface-chip rounded-lg group hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            >
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <Icon icon="heroicons:document-text" class="w-5 h-5 txt-secondary flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium txt-primary truncate">{{ file.fileName }}</p>
                  <p class="text-xs txt-secondary">
                    {{ file.chunks }} chunks • 
                    {{ file.uploadedAt ? formatDate(file.uploadedAt) : 'Unknown date' }}
                  </p>
                </div>
              </div>
              <button
                @click="handleDeleteFile(file.messageId)"
                :disabled="loading"
                class="w-8 h-8 rounded-lg hover:bg-red-500/10 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
                title="Unlink file from this prompt"
              >
                <Icon icon="heroicons:x-mark" class="w-4 h-4 text-red-500" />
              </button>
            </div>
          </div>
        </div>

        <!-- Link Existing Files Section -->
        <div class="space-y-4">
          <!-- Search Filter -->
          <div class="relative">
            <Icon icon="heroicons:magnifying-glass" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary" />
            <input
              v-model="availableFilesSearch"
              @input="loadAvailableFiles"
              type="text"
              placeholder="Search files by name..."
              class="w-full pl-10 pr-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            />
          </div>

          <!-- Loading -->
          <div v-if="loadingAvailableFiles" class="text-center py-8">
            <Icon icon="heroicons:arrow-path" class="w-8 h-8 mx-auto mb-2 txt-secondary animate-spin" />
            <p class="text-sm txt-secondary">Loading files...</p>
          </div>

          <!-- Available Files List -->
          <div v-else-if="availableFiles.length > 0" class="space-y-2 max-h-[400px] overflow-y-auto">
            <div
              v-for="file in availableFiles"
              :key="file.messageId"
              class="flex items-center justify-between p-3 surface-chip rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
            >
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <Icon icon="heroicons:document-text" class="w-5 h-5 txt-secondary flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium txt-primary truncate">{{ file.fileName }}</p>
                  <p class="text-xs txt-secondary">
                    {{ file.chunks }} chunks
                    <template v-if="file.currentGroupKey !== 'DEFAULT'">
                      • Currently linked to: <span class="font-mono">{{ file.currentGroupKey }}</span>
                    </template>
                  </p>
                </div>
              </div>
              <button
                @click="handleLinkFile(file.messageId)"
                :disabled="loading || isFileLinked(file.messageId)"
                :class="[
                  'px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2',
                  isFileLinked(file.messageId)
                    ? 'bg-green-500/10 text-green-600 dark:text-green-400 cursor-default'
                    : 'bg-[var(--brand)]/10 text-[var(--brand)] hover:bg-[var(--brand)]/20'
                ]"
              >
                <Icon 
                  :icon="isFileLinked(file.messageId) ? 'heroicons:check-circle' : 'heroicons:link'" 
                  class="w-4 h-4" 
                />
                {{ isFileLinked(file.messageId) ? 'Linked' : 'Link' }}
              </button>
            </div>
          </div>

          <!-- Empty State -->
          <div v-else class="text-center py-8">
            <Icon icon="heroicons:document-magnifying-glass" class="w-12 h-12 mx-auto mb-2 txt-secondary opacity-30" />
            <p class="text-sm txt-secondary">
              {{ availableFilesSearch ? 'No files found matching your search' : 'No vectorized files available. Upload files in the Files page first.' }}
            </p>
          </div>
        </div>
      </div>

      <!-- Create New Prompt Card -->
      <div class="surface-card p-6">
        <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
          <Icon icon="heroicons:plus-circle" class="w-5 h-5 text-[var(--brand)]" />
          {{ $t('config.taskPrompts.createNew') }}
        </h3>

        <div class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:tag" class="w-4 h-4" />
                {{ $t('config.taskPrompts.topic') }}
              </label>
              <input
                v-model="newPromptTopic"
                type="text"
                class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('config.taskPrompts.topicPlaceholder')"
              />
            </div>
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2 flex items-center gap-2">
                <Icon icon="heroicons:pencil" class="w-4 h-4" />
                {{ $t('config.taskPrompts.name') }}
              </label>
              <input
                v-model="newPromptName"
                type="text"
                class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('config.taskPrompts.namePlaceholder')"
        />
      </div>
    </div>

          <button
            @click="handleCreateNew"
            :disabled="!newPromptName.trim() || !newPromptTopic.trim() || loading"
            class="w-full px-6 py-3 rounded-lg border-2 border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
          >
            <Icon icon="heroicons:plus-circle" class="w-5 h-5" />
            {{ $t('config.taskPrompts.createButton') }}
          </button>
        </div>
      </div>

      <!-- Delete Prompt (only for custom prompts) -->
      <div v-if="!currentPrompt.isDefault" class="surface-card p-6 border-2 border-red-500/20">
        <h3 class="text-lg font-semibold text-red-600 dark:text-red-400 mb-2 flex items-center gap-2">
          <Icon icon="heroicons:trash" class="w-5 h-5" />
          {{ $t('config.taskPrompts.dangerZone') }}
        </h3>
        <p class="text-sm txt-secondary mb-4">{{ $t('config.taskPrompts.deleteWarning') }}</p>
        <button
          @click="handleDelete"
          :disabled="loading"
          class="px-6 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-500/20 transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
        >
          <Icon icon="heroicons:trash" class="w-5 h-5" />
          {{ $t('config.taskPrompts.deletePrompt') }}
        </button>
      </div>
    </template>

    <!-- Unsaved Changes Bar -->
    <UnsavedChangesBar
      :show="hasUnsavedChanges"
      @save="handleSave"
      @discard="handleDiscard"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import { promptsApi, type TaskPrompt as ApiTaskPrompt, type PromptFile, type AvailableFile } from '@/services/api/promptsApi'
import { configApi, type ModelInfo } from '@/services/api/configApi'
import { useNotification } from '@/composables/useNotification'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { useDialog } from '@/composables/useDialog'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'

// Extended TaskPrompt interface with UI fields
interface TaskPrompt extends ApiTaskPrompt {
  rules?: string
  aiModel?: string
  availableTools?: string[]
  content: string
}

// Tool definition
interface ToolOption {
  value: string
  label: string
  icon: string
}

const { success, error: showError } = useNotification()
const dialog = useDialog()

const prompts = ref<TaskPrompt[]>([])
const selectedPromptId = ref<number | null>(null)
const currentPrompt = ref<TaskPrompt | null>(null)
const formData = ref<Partial<TaskPrompt>>({})
const originalData = ref<Partial<TaskPrompt>>({})
const newPromptName = ref('')
const newPromptTopic = ref('')
const contentTextarea = ref<HTMLTextAreaElement | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

// Files for current prompt
const promptFiles = ref<PromptFile[]>([])

// Available files for linking
const availableFiles = ref<AvailableFile[]>([])
const availableFilesSearch = ref('')
const loadingAvailableFiles = ref(false)

// Models from API
const allModels = ref<{ [capability: string]: ModelInfo[] }>({})
const loadingModels = ref(false)

// Available tools with icons (removed image/video generation as they're not clickable tools)
const availableTools: ToolOption[] = [
  { value: 'internet-search', label: 'Internet Search', icon: 'heroicons:magnifying-glass' },
  { value: 'files-search', label: 'Files Search', icon: 'heroicons:document-magnifying-glass' },
  { value: 'url-screenshot', label: 'URL Screenshot', icon: 'heroicons:camera' }
]

// Group models by capability for dropdown
const groupedModels = computed(() => {
  const groups: { label: string; models: ModelInfo[]; capability: string }[] = []
  
  const capabilityLabels: Record<string, string> = {
    'CHAT': 'Chat & General AI',
    'SORT': 'Message Sorting',
    'TEXT2PIC': 'Image Generation',
    'TEXT2VID': 'Video Generation',
    'TEXT2SOUND': 'Text-to-Speech',
    'SOUND2TEXT': 'Speech-to-Text',
    'PIC2TEXT': 'Vision (Image Analysis)',
    'VECTORIZE': 'Embedding / RAG',
    'ANALYZE': 'File Analysis'
  }
  
  // Order of capabilities in dropdown
  const orderedCapabilities = ['CHAT', 'TEXT2PIC', 'TEXT2VID', 'TEXT2SOUND', 'SOUND2TEXT', 'PIC2TEXT', 'ANALYZE', 'VECTORIZE', 'SORT']
  
  orderedCapabilities.forEach(capability => {
    if (allModels.value[capability] && allModels.value[capability].length > 0) {
      groups.push({
        label: capabilityLabels[capability] || capability,
        models: allModels.value[capability],
        capability
      })
    }
  })
  
  return groups
})

const markdownTools = [
  { icon: 'heroicons:bold', label: 'Bold', before: '**', after: '**' },
  { icon: 'heroicons:italic', label: 'Italic', before: '*', after: '*' },
  { icon: 'heroicons:hashtag', label: 'Heading', before: '# ', after: '' },
  { icon: 'heroicons:code-bracket', label: 'Code', before: '`', after: '`' },
  { icon: 'heroicons:list-bullet', label: 'List', before: '- ', after: '' },
  { icon: 'heroicons:link', label: 'Link', before: '[', after: '](url)' },
]

// Unsaved changes tracking
const { hasUnsavedChanges, saveChanges, discardChanges, setupNavigationGuard } = useUnsavedChanges(
  formData as any,
  originalData as any
)

let cleanupGuard: (() => void) | undefined

/**
 * Load AI models from API
 */
const loadAIModels = async () => {
  loadingModels.value = true
  try {
    const response = await configApi.getModels()
    if (response.success) {
      allModels.value = response.models
    }
  } catch (err: any) {
    console.error('Failed to load AI models:', err)
  } finally {
    loadingModels.value = false
  }
}

/**
 * Load all prompts from API
 */
const loadPrompts = async () => {
  loading.value = true
  error.value = null
  
  try {
    const data = await promptsApi.getPrompts('en')
    prompts.value = data.map(p => {
      // Parse metadata from backend
      const metadata = p.metadata || {}
      
      // Determine AI Model string from metadata.aiModel (ID)
      let aiModelString = 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]'
      if (metadata.aiModel && metadata.aiModel > 0) {
        // Find model by ID in all capabilities
        let foundModel = null
        for (const capability in allModels.value) {
          const models = allModels.value[capability]
          foundModel = models.find((m: any) => m.id === metadata.aiModel)
          if (foundModel) break
        }
        if (foundModel) {
          aiModelString = `${foundModel.name} (${foundModel.service})`
        }
      }
      
      // Parse available tools from metadata (tool_* keys)
      const availableTools: string[] = []
      if (metadata.tool_internet_search) availableTools.push('internet-search')
      if (metadata.tool_files_search) availableTools.push('files-search')
      if (metadata.tool_url_screenshot) availableTools.push('url-screenshot')
      
      return {
        ...p,
        content: p.prompt,
        rules: p.selectionRules || p.shortDescription,
        aiModel: aiModelString,
        availableTools
      }
    })
    
    if (prompts.value.length > 0 && !selectedPromptId.value) {
      selectedPromptId.value = prompts.value[0].id
      loadPrompt()
    }
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to load prompts'
    error.value = errorMessage
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

/**
 * Load selected prompt
 */
const loadPrompt = () => {
  const prompt = prompts.value.find(p => p.id === selectedPromptId.value)
  if (prompt) {
    currentPrompt.value = { ...prompt }
    formData.value = {
      rules: prompt.rules,
      aiModel: prompt.aiModel,
      availableTools: prompt.availableTools,
      content: prompt.content
    }
    originalData.value = { ...formData.value }
    
    // Load files for this prompt
    loadPromptFiles()
  }
}

/**
 * Handle prompt selection
 */
const onPromptSelect = async () => {
  if (hasUnsavedChanges.value) {
    const confirmed = await dialog.confirm({
      title: 'Unsaved Changes',
      message: 'You have unsaved changes. Do you want to discard them?',
      confirmText: 'Discard',
      cancelText: 'Cancel',
      danger: true
    })
    
    if (!confirmed) {
      selectedPromptId.value = currentPrompt.value?.id || null
      return
    }
  }
  loadPrompt()
}

/**
 * Insert markdown formatting
 */
const insertMarkdown = (before: string, after: string) => {
  const textarea = contentTextarea.value
  if (!textarea || !formData.value.content) return
  
  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const text = formData.value.content
  const selectedText = text.substring(start, end)
  
  formData.value.content =
    text.substring(0, start) +
    before +
    selectedText +
    after +
    text.substring(end)
  
  setTimeout(() => {
    textarea.focus()
    textarea.setSelectionRange(start + before.length, end + before.length)
  }, 0)
}

/**
 * Handle save
 */
const handleSave = saveChanges(async () => {
  if (!currentPrompt.value) return
  
  try {
    // Build metadata object
    const metadata: Record<string, any> = {}
    
    // Parse AI Model from dropdown string back to ID (for SAVE)
    if (formData.value.aiModel !== 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]') {
      const selectedModelString = formData.value.aiModel
      // Find model by ID in all capabilities
      let foundModel = null
      for (const capability in allModels.value) {
        const models = allModels.value[capability]
        foundModel = models.find((m: any) => `${m.name} (${m.service})` === selectedModelString)
        if (foundModel) break
      }
      if (foundModel) {
        metadata.aiModel = foundModel.id
      }
    } else {
      metadata.aiModel = -1 // AUTOMATED
    }
    
    // Set tool flags (for SAVE)
    metadata.tool_internet_search = (formData.value.availableTools || []).includes('internet-search')
    metadata.tool_files_search = (formData.value.availableTools || []).includes('files-search')
    metadata.tool_url_screenshot = (formData.value.availableTools || []).includes('url-screenshot')
    
    // If it's a system prompt (isDefault=true and no user override), 
    // we need to CREATE a user override instead of UPDATE
    if (currentPrompt.value.isDefault && !currentPrompt.value.isUserOverride) {
      // Create user override
      const newPrompt = await promptsApi.createPrompt({
        topic: currentPrompt.value.topic,
        shortDescription: currentPrompt.value.shortDescription,
        prompt: formData.value.content || '',
        language: currentPrompt.value.language || 'en',
        selectionRules: formData.value.rules || null,
        metadata
      })
      
      // Update local state - replace system prompt with user override
      const index = prompts.value.findIndex(p => p.id === currentPrompt.value!.id)
      if (index !== -1) {
        prompts.value[index] = {
          ...newPrompt,
          content: newPrompt.prompt,
          rules: newPrompt.selectionRules || newPrompt.shortDescription,
          aiModel: formData.value.aiModel,
          availableTools: formData.value.availableTools,
          isUserOverride: true
        }
        currentPrompt.value = { ...prompts.value[index] }
        selectedPromptId.value = newPrompt.id
        originalData.value = { ...formData.value }
      }
      
      success('User override created successfully!')
    } else {
      // Update existing user prompt
      const updated = await promptsApi.updatePrompt(currentPrompt.value.id, {
        shortDescription: currentPrompt.value.shortDescription, // Keep original name
        prompt: formData.value.content || '',
        selectionRules: formData.value.rules || null,
        metadata
      })
      
      // Update local state
      const index = prompts.value.findIndex(p => p.id === currentPrompt.value!.id)
      if (index !== -1) {
        prompts.value[index] = {
          ...updated,
          content: updated.prompt,
          rules: updated.selectionRules || updated.shortDescription,
          aiModel: formData.value.aiModel,
          availableTools: formData.value.availableTools
        }
        currentPrompt.value = { ...prompts.value[index] }
        originalData.value = { ...formData.value }
      }
      
      success('Prompt updated successfully!')
    }
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to save prompt'
    showError(errorMessage)
    throw err
  }
})

/**
 * Handle discard
 */
const handleDiscard = () => {
  discardChanges()
}

/**
 * Create a new custom prompt
 */
const handleCreateNew = async () => {
  if (!newPromptName.value.trim() || !newPromptTopic.value.trim() || !formData.value.content || loading.value) {
    showError('Please enter topic, name, and prompt content')
    return
  }
  
  loading.value = true
  
  try {
    // Build metadata object
    const metadata: Record<string, any> = {}
    
    // Parse AI Model from dropdown string back to ID (for CREATE)
    if (formData.value.aiModel !== 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]') {
      const selectedModelString = formData.value.aiModel
      // Find model by ID in all capabilities
      let foundModel = null
      for (const capability in allModels.value) {
        const models = allModels.value[capability]
        foundModel = models.find((m: any) => `${m.name} (${m.service})` === selectedModelString)
        if (foundModel) break
      }
      if (foundModel) {
        metadata.aiModel = foundModel.id
      }
    } else {
      metadata.aiModel = -1 // AUTOMATED
    }
    
    // Set tool flags (for CREATE)
    metadata.tool_internet_search = (formData.value.availableTools || []).includes('internet-search')
    metadata.tool_files_search = (formData.value.availableTools || []).includes('files-search')
    metadata.tool_url_screenshot = (formData.value.availableTools || []).includes('url-screenshot')
    
    const newPrompt = await promptsApi.createPrompt({
      topic: newPromptTopic.value.trim().toLowerCase().replace(/\s+/g, '-'),
      shortDescription: newPromptName.value.trim(),
      prompt: formData.value.content,
      language: 'en',
      selectionRules: formData.value.rules || null,
      metadata
    })
    
    // Add to local state
    const mappedPrompt: TaskPrompt = {
      ...newPrompt,
      content: newPrompt.prompt,
      rules: newPrompt.selectionRules || newPrompt.shortDescription,
      aiModel: formData.value.aiModel || 'AUTOMATED - Tries to define the best model for the task on SYNAPLAN [System Model]',
      availableTools: formData.value.availableTools || []
    }
    
    prompts.value.push(mappedPrompt)
    selectedPromptId.value = newPrompt.id
    currentPrompt.value = { ...mappedPrompt }
    formData.value = {
      rules: mappedPrompt.rules,
      aiModel: mappedPrompt.aiModel,
      availableTools: mappedPrompt.availableTools,
      content: mappedPrompt.content
    }
    originalData.value = { ...formData.value }
    newPromptName.value = ''
    newPromptTopic.value = ''
    
    success('Custom prompt created successfully!')
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to create prompt'
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

/**
 * Delete a custom prompt
 */
const handleDelete = async () => {
  if (!currentPrompt.value || currentPrompt.value.isDefault || loading.value) {
    return
  }
  
  const confirmed = await dialog.confirm({
    title: 'Delete Prompt',
    message: `Are you sure you want to delete "${currentPrompt.value.name}"? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    danger: true
  })
  
  if (!confirmed) return
  
  loading.value = true
  
  try {
    await promptsApi.deletePrompt(currentPrompt.value.id)
    
    // Remove from local state
    const index = prompts.value.findIndex(p => p.id === currentPrompt.value!.id)
    if (index !== -1) {
      prompts.value.splice(index, 1)
      if (prompts.value.length > 0) {
        selectedPromptId.value = prompts.value[0].id
        loadPrompt()
      } else {
        selectedPromptId.value = null
        currentPrompt.value = null
      }
    }
    
    success('Prompt deleted successfully!')
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to delete prompt'
    showError(errorMessage)
  } finally {
    loading.value = false
  }
}

/**
 * Load files for current prompt
 */
const loadPromptFiles = async () => {
  if (!currentPrompt.value?.topic) {
    promptFiles.value = []
    return
  }
  
  try {
    promptFiles.value = await promptsApi.getPromptFiles(currentPrompt.value.topic)
  } catch (err: any) {
    console.error('Failed to load prompt files:', err)
    promptFiles.value = []
  }
}

/**
 * Delete file from prompt
 */
const handleDeleteFile = async (messageId: number) => {
  if (!currentPrompt.value?.topic) return
  
  const confirmed = await dialog.confirm({
    title: 'Delete File',
    message: 'Are you sure you want to remove this file from the knowledge base? This action cannot be undone.',
    confirmText: 'Delete',
    cancelText: 'Cancel',
    danger: true
  })
  
  if (!confirmed) return
  
  try {
    await promptsApi.deletePromptFile(currentPrompt.value.topic, messageId)
    success('File removed from knowledge base')
    
    // Reload files list
    await loadPromptFiles()
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to delete file'
    showError(errorMessage)
  }
}

/**
 * Format date for display
 */
const formatDate = (dateString: string): string => {
  const date = new Date(dateString)
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)
  
  if (diffInSeconds < 60) return 'Just now'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`
  return date.toLocaleDateString()
}

/**
 * Load available files for linking
 */
const loadAvailableFiles = async () => {
  loadingAvailableFiles.value = true
  try {
    availableFiles.value = await promptsApi.getAvailableFiles(availableFilesSearch.value)
  } catch (err: any) {
    console.error('Failed to load available files:', err)
    availableFiles.value = []
  } finally {
    loadingAvailableFiles.value = false
  }
}

/**
 * Check if file is already linked to current prompt
 */
const isFileLinked = (messageId: number): boolean => {
  return promptFiles.value.some(f => f.messageId === messageId)
}

/**
 * Link existing file to current prompt
 */
const handleLinkFile = async (messageId: number) => {
  if (!currentPrompt.value?.topic) return
  
  try {
    await promptsApi.linkFileToPrompt(currentPrompt.value.topic, messageId)
    success('File linked successfully!')
    
    // Reload both lists
    await Promise.all([
      loadPromptFiles(),
      loadAvailableFiles()
    ])
  } catch (err: any) {
    const errorMessage = err.message || 'Failed to link file'
    showError(errorMessage)
  }
}

onMounted(() => {
  cleanupGuard = setupNavigationGuard()
  Promise.all([
    loadAIModels(),
    loadPrompts(),
    loadAvailableFiles() // Load available files on mount
  ])
})

onUnmounted(() => {
  cleanupGuard?.()
})
</script>
