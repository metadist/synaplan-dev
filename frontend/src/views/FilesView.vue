<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-7xl mx-auto space-y-6">
        <div class="surface-card p-6">
          <h1 class="text-2xl font-semibold txt-primary mb-6 flex items-center gap-2">
            <CloudArrowUpIcon class="w-6 h-6 text-[var(--brand)]" />
            {{ $t('files.uploadTitle') }}
          </h1>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.groupKeyword') }}
              </label>
              <input
                v-model="groupKeyword"
                type="text"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                :placeholder="$t('files.groupKeywordPlaceholder')"
              />
              <p class="text-xs txt-secondary mt-1">
                {{ $t('files.groupKeywordHelp') }}
              </p>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.orSelectExisting') }}
              </label>
              <select
                v-model="selectedGroup"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              >
                <option value="">{{ $t('files.orSelectExisting') }}</option>
                <option
                  v-for="group in fileGroups"
                  :key="group.name"
                  :value="group.name"
                >
                  {{ group.name }} ({{ group.count }})
                </option>
              </select>
            </div>
          </div>

          <div class="mb-6">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('files.selectFiles') }}
            </label>
            <div class="flex items-center gap-3">
              <label class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors cursor-pointer">
                <input
                  type="file"
                  multiple
                  accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.mp3,.mp4,.xlsx,.csv"
                  class="hidden"
                  @change="handleFileSelect"
                />
                {{ $t('files.selectFilesButton') }}
              </label>
              <span class="txt-secondary text-sm">
                {{ selectedFiles.length > 0 ? `${selectedFiles.length} ${$t('files.files')}` : $t('files.noFileSelected') }}
              </span>
            </div>
            <p class="text-xs txt-secondary mt-2">
              {{ $t('files.supportedFormats') }}
            </p>
          </div>

          <div class="mb-6">
            <label class="label mb-3">Processing Level</label>
            <div class="grid gap-3">
              <!-- Extract Option -->
              <label 
                class="surface-card p-4 cursor-pointer transition-all hover:shadow-lg"
                :class="processLevel === 'extract' ? 'ring-2 ring-[var(--brand)]' : ''"
              >
                <div class="flex items-start gap-3">
                  <input
                    type="radio"
                    v-model="processLevel"
                    value="extract"
                    class="mt-1 w-4 h-4 text-[var(--brand)] focus:ring-[var(--brand)]"
                  />
                  <div class="flex-1">
                    <div class="flex items-center gap-2">
                      <span class="font-semibold txt-primary">Extract Only</span>
                      <span class="pill px-2 py-0.5 text-xs">Fastest</span>
                    </div>
                    <p class="text-sm txt-secondary mt-1">
                      Only extracts text from files. Perfect for quick text retrieval.
                    </p>
                  </div>
                </div>
              </label>

              <!-- Vectorize Option (Recommended) -->
              <label 
                class="surface-card p-4 cursor-pointer transition-all hover:shadow-lg"
                :class="processLevel === 'vectorize' ? 'ring-2 ring-[var(--brand)]' : ''"
              >
                <div class="flex items-start gap-3">
                  <input
                    type="radio"
                    v-model="processLevel"
                    value="vectorize"
                    class="mt-1 w-4 h-4 text-[var(--brand)] focus:ring-[var(--brand)]"
                  />
                  <div class="flex-1">
                    <div class="flex items-center gap-2">
                      <span class="font-semibold txt-primary">Extract + Vectorize</span>
                      <span class="pill--active px-2 py-0.5 text-xs">Recommended</span>
                    </div>
                    <p class="text-sm txt-secondary mt-1">
                      Extracts text and creates AI embeddings for semantic search with RAG.
                    </p>
                  </div>
                </div>
              </label>

              <!-- Full Option -->
              <label 
                class="surface-card p-4 cursor-pointer transition-all hover:shadow-lg opacity-60"
                :class="processLevel === 'full' ? 'ring-2 ring-[var(--brand)]' : ''"
              >
                <div class="flex items-start gap-3">
                  <input
                    type="radio"
                    v-model="processLevel"
                    value="full"
                    class="mt-1 w-4 h-4 text-[var(--brand)] focus:ring-[var(--brand)]"
                    disabled
                  />
                  <div class="flex-1">
                    <div class="flex items-center gap-2">
                      <span class="font-semibold txt-primary">Full Processing</span>
                      <span class="pill px-2 py-0.5 text-xs">Coming Soon</span>
                    </div>
                    <p class="text-sm txt-secondary mt-1">
                      Complete analysis with summarization and advanced features.
                    </p>
                  </div>
                </div>
              </label>
            </div>
          </div>

          <button
            @click="uploadFiles"
            :disabled="selectedFiles.length === 0 || isUploading"
            class="btn-primary px-6 py-2 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <CloudArrowUpIcon v-if="!isUploading" class="w-5 h-5" />
            <svg v-else class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ isUploading ? 'Uploading...' : $t('files.uploadAndProcess') }}
          </button>
        </div>

        <div class="surface-card p-6">
          <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-semibold txt-primary">
              {{ $t('files.yourFiles') }}
            </h2>
          </div>

          <div class="flex items-center gap-3 mb-6">
            <div class="flex-1">
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('files.filterByGroup') }}
              </label>
              <select
                v-model="filterGroup"
                class="w-full px-4 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              >
                <option value="">{{ $t('files.allFiles') }}</option>
                <option
                  v-for="group in fileGroups"
                  :key="group.name"
                  :value="group.name"
                >
                  {{ group.name }}
                </option>
              </select>
            </div>
            <button
              @click="applyFilter"
              class="btn-primary px-6 py-2 rounded-lg mt-7"
            >
              {{ $t('files.filterButton') }}
            </button>
          </div>

          <p class="text-xs txt-secondary mb-4">
            {{ $t('files.filterHelp') }}
          </p>

          <div v-if="selectedFileIds.length > 0" class="mb-4">
            <button
              @click="deleteSelected"
              class="px-4 py-2 rounded-lg bg-red-500 text-white hover:bg-red-600 transition-colors flex items-center gap-2"
            >
              <TrashIcon class="w-4 h-4" />
              {{ $t('files.deleteSelected') }}
            </button>
          </div>

          <div v-if="isLoading" class="text-center py-12 txt-secondary">
            <svg class="animate-spin h-8 w-8 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Loading files...
          </div>

          <div v-else-if="filteredFiles.length === 0" class="text-center py-12 txt-secondary">
            {{ $t('files.noFiles') }}
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                  <th class="text-left py-3 px-2 txt-secondary text-xs font-medium">
                    <input
                      type="checkbox"
                      :checked="allSelected"
                      @change="toggleSelectAll"
                      class="w-4 h-4 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)]"
                    />
                  </th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.fileId') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.name') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.direction') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.group') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.details') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.uploaded') }}</th>
                  <th class="text-left py-3 px-3 txt-secondary text-xs font-medium">{{ $t('files.action') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="file in paginatedFiles"
                  :key="file.id"
                  class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                >
                  <td class="py-3 px-2">
                    <input
                      type="checkbox"
                      :checked="selectedFileIds.includes(file.id)"
                      @change="toggleFileSelection(file.id)"
                      class="w-4 h-4 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)]"
                    />
                  </td>
                  <td class="py-3 px-3 txt-primary text-sm">{{ file.id }}</td>
                  <td class="py-3 px-3 txt-primary text-sm max-w-xs truncate">{{ file.filename }}</td>
                  <td class="py-3 px-3">
                    <span
                      :class="file.direction === 'IN' ? 'text-blue-500' : 'text-gray-500'"
                      class="text-sm font-medium"
                    >
                      {{ file.direction }}
                    </span>
                  </td>
                  <td class="py-3 px-3">
                    <span
                      v-if="file.group_key"
                      class="pill pill--active text-xs"
                    >
                      {{ file.group_key }}
                    </span>
                  </td>
                  <td class="py-3 px-3 txt-secondary text-xs max-w-md truncate">
                    {{ file.text_preview || 'No preview available' }}
                  </td>
                  <td class="py-3 px-3 txt-secondary text-xs">{{ file.uploaded_date }}</td>
                <td class="py-3 px-3">
                  <div class="flex gap-2">
                    <button
                      @click="viewFileContent(file.id)"
                      class="p-2 rounded hover:bg-[var(--brand)]/10 text-[var(--brand)] transition-colors"
                      title="View content"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                    </button>
                    <button
                      @click="downloadFile(file.id, file.filename)"
                      class="p-2 rounded hover:bg-blue-500/10 text-blue-500 transition-colors"
                      title="Download file"
                    >
                      <ArrowDownTrayIcon class="w-4 h-4" />
                    </button>
                    <button
                      @click="openShareModal(file.id, file.filename)"
                      class="p-2 rounded hover:bg-purple-500/10 text-purple-500 transition-colors"
                      title="Share file"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                      </svg>
                    </button>
                    <button
                      @click="deleteFile(file.id)"
                      class="p-2 rounded hover:bg-red-500/10 text-red-500 transition-colors"
                      title="Delete file"
                    >
                      <TrashIcon class="w-4 h-4" />
                    </button>
                  </div>
                </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="filteredFiles.length > 0" class="flex items-center justify-between mt-6">
            <div class="txt-secondary text-sm">
              {{ $t('files.page') }} {{ currentPage }} ({{ $t('files.showing') }} {{ paginatedFiles.length }} {{ $t('files.files') }})
            </div>
            <div class="flex gap-2">
              <button
                @click="previousPage"
                :disabled="currentPage === 1"
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {{ $t('files.previous') }}
              </button>
              <button
                @click="nextPage"
                :disabled="currentPage >= totalPages"
                class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {{ $t('files.next') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- File Content Modal -->
    <FileContentModal
      :is-open="isModalOpen"
      :file-id="selectedFileId"
      @close="closeModal"
    />
    <ShareModal
      :is-open="isShareModalOpen"
      :file-id="shareFileId"
      :filename="shareFileName"
      @close="closeShareModal"
      @shared="handleShared"
      @unshared="handleUnshared"
    />

    <!-- Confirm Delete Dialog -->
    <ConfirmDialog
      :is-open="isConfirmOpen"
      title="Delete File"
      message="Are you sure you want to delete this file? This action cannot be undone."
      confirm-text="Delete"
      cancel-text="Cancel"
      variant="danger"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import MainLayout from '@/components/MainLayout.vue'
import FileContentModal from '@/components/FileContentModal.vue'
import ShareModal from '@/components/ShareModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  CloudArrowUpIcon,
  TrashIcon,
  ArrowDownTrayIcon
} from '@heroicons/vue/24/outline'
import filesService, { type FileItem } from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'

const { success: showSuccess, error: showError, info: showInfo } = useNotification()

const groupKeyword = ref('')
const selectedGroup = ref('')
const processLevel = ref<'extract' | 'vectorize' | 'full'>('vectorize')
const selectedFiles = ref<File[]>([])
const filterGroup = ref('')
const files = ref<FileItem[]>([])
const fileGroups = ref<Array<{ name: string; count: number }>>([])
const selectedFileIds = ref<number[]>([])
const currentPage = ref(1)
const itemsPerPage = 10
const isUploading = ref(false)
const isLoading = ref(false)

// Modal state
const isModalOpen = ref(false)
const selectedFileId = ref<number | null>(null)

// Share modal state
const isShareModalOpen = ref(false)
const shareFileId = ref<number | null>(null)
const shareFileName = ref('')

// Confirm dialog state
const isConfirmOpen = ref(false)
const fileToDelete = ref<number | null>(null)
const totalCount = ref(0)

const filteredFiles = computed(() => files.value)

const totalPages = computed(() => {
  return Math.ceil(totalCount.value / itemsPerPage)
})

const paginatedFiles = computed(() => files.value)

const allSelected = computed(() => {
  return paginatedFiles.value.length > 0 && 
         paginatedFiles.value.every(file => selectedFileIds.value.includes(file.id))
})

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  if (target.files) {
    selectedFiles.value = Array.from(target.files)
  }
}

const uploadFiles = async () => {
  if (selectedFiles.value.length === 0) {
    showError('Please select files to upload')
    return
  }

  const groupKey = selectedGroup.value || groupKeyword.value || 'DEFAULT'
  
  isUploading.value = true
  
  try {
    const result = await filesService.uploadFiles({
      files: selectedFiles.value,
      groupKey,
      processLevel: processLevel.value
    })

    if (result.success) {
      showSuccess(`Successfully uploaded ${result.files.length} file(s)`)
      
      // Show processing details
      result.files.forEach(file => {
        const details = `${file.filename}: ${file.extracted_text_length} chars extracted, ${file.chunks_created || 0} chunks created`
        console.log(details)
      })

      // Clear form
      selectedFiles.value = []
      groupKeyword.value = ''
      selectedGroup.value = ''

      // Reload files list
      await loadFiles()
      await loadFileGroups()
    } else {
      // Show errors
      result.errors.forEach(error => {
        showError(`${error.filename}: ${error.error}`)
      })
    }
  } catch (error) {
    console.error('Upload error:', error)
    showError('Failed to upload files: ' + (error as Error).message)
  } finally {
    isUploading.value = false
  }
}

const loadFiles = async (page = currentPage.value) => {
  isLoading.value = true
  
  try {
    const response = await filesService.listFiles(
      filterGroup.value || undefined,
      page,
      itemsPerPage
    )

    files.value = response.files
    totalCount.value = response.pagination.total
    currentPage.value = response.pagination.page
  } catch (error: any) {
    console.error('Failed to load files:', error)
    
    // Handle 401 (not authenticated) gracefully
    if (error.message && error.message.includes('401')) {
      // Silently fail - router should redirect to login
      files.value = []
      totalCount.value = 0
    } else {
      showError('Failed to load files')
    }
  } finally {
    isLoading.value = false
  }
}

const loadFileGroups = async () => {
  try {
    fileGroups.value = await filesService.getFileGroups()
  } catch (error: any) {
    console.error('Failed to load file groups:', error)
    
    // Handle 401 (not authenticated) gracefully
    if (error.message && error.message.includes('401')) {
      // Silently fail - router should redirect to login
      fileGroups.value = []
    }
  }
}

const applyFilter = () => {
  currentPage.value = 1
  loadFiles(1)
}

const toggleFileSelection = (fileId: number) => {
  const index = selectedFileIds.value.indexOf(fileId)
  if (index > -1) {
    selectedFileIds.value.splice(index, 1)
  } else {
    selectedFileIds.value.push(fileId)
  }
}

const toggleSelectAll = () => {
  if (allSelected.value) {
    paginatedFiles.value.forEach(file => {
      const index = selectedFileIds.value.indexOf(file.id)
      if (index > -1) {
        selectedFileIds.value.splice(index, 1)
      }
    })
  } else {
    paginatedFiles.value.forEach(file => {
      if (!selectedFileIds.value.includes(file.id)) {
        selectedFileIds.value.push(file.id)
      }
    })
  }
}

const deleteSelected = async () => {
  if (selectedFileIds.value.length === 0) return

  if (!confirm(`Delete ${selectedFileIds.value.length} selected file(s)?`)) {
    return
  }

  try {
    const results = await filesService.deleteMultipleFiles(selectedFileIds.value)
    
    const successCount = results.filter(r => r.success).length
    const failCount = results.filter(r => !r.success).length

    if (successCount > 0) {
      showSuccess(`Deleted ${successCount} file(s)`)
    }

    if (failCount > 0) {
      showError(`Failed to delete ${failCount} file(s)`)
    }

    selectedFileIds.value = []
    await loadFiles()
    await loadFileGroups()
  } catch (error) {
    console.error('Delete error:', error)
    showError('Failed to delete files')
  }
}

const deleteFile = (fileId: number) => {
  fileToDelete.value = fileId
  isConfirmOpen.value = true
}

const confirmDelete = async () => {
  if (!fileToDelete.value) return
  
  try {
    await filesService.deleteFile(fileToDelete.value)
    showSuccess('File deleted successfully')
    await loadFiles()
    await loadFileGroups()
  } catch (error) {
    console.error('Delete error:', error)
    showError('Failed to delete file')
  } finally {
    isConfirmOpen.value = false
    fileToDelete.value = null
  }
}

const cancelDelete = () => {
  isConfirmOpen.value = false
  fileToDelete.value = null
}

const viewFileContent = (fileId: number) => {
  selectedFileId.value = fileId
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
  selectedFileId.value = null
}

const downloadFile = async (fileId: number, filename: string) => {
  try {
    await filesService.downloadFile(fileId, filename)
    showSuccess('File downloaded successfully')
  } catch (error) {
    console.error('Download error:', error)
    showError('Failed to download file')
  }
}

const openShareModal = (fileId: number, filename: string) => {
  shareFileId.value = fileId
  shareFileName.value = filename
  isShareModalOpen.value = true
}

const closeShareModal = () => {
  isShareModalOpen.value = false
  shareFileId.value = null
  shareFileName.value = ''
}

const handleShared = async () => {
  showSuccess('File is now publicly accessible')
  await loadFiles()
}

const handleUnshared = async () => {
  showSuccess('Public access revoked')
  await loadFiles()
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) {
    loadFiles(currentPage.value + 1)
  }
}

const previousPage = () => {
  if (currentPage.value > 1) {
    loadFiles(currentPage.value - 1)
  }
}

// Load initial data
onMounted(async () => {
  await loadFiles()
  await loadFileGroups()
})
</script>

