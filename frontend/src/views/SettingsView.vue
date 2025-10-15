<template>
  <MainLayout>
    <div class="h-full flex flex-col bg-app">
      <div class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20">
        <h1 class="text-2xl font-semibold txt-primary mb-1">⚙️ Settings</h1>
        <p class="txt-secondary text-sm">Manage your preferences and configuration</p>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-6">
        <div class="max-w-3xl mx-auto space-y-6">
          <!-- App Mode -->
          <div class="surface-card p-6">
            <h2 class="text-lg font-semibold txt-primary mb-2">App Mode</h2>
            <p class="txt-secondary text-sm mb-4">Choose between simplified or full feature set</p>
            
            <div class="grid grid-cols-2 gap-3">
              <button
                @click="appModeStore.setMode('easy')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all text-left',
                  appModeStore.isEasyMode
                    ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                    : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50'
                ]"
              >
                <div class="font-semibold txt-primary mb-1">Easy Mode</div>
                <div class="text-sm txt-secondary">Simplified interface with essential features</div>
              </button>
              
              <button
                @click="appModeStore.setMode('advanced')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all text-left',
                  appModeStore.isAdvancedMode
                    ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                    : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50'
                ]"
              >
                <div class="font-semibold txt-primary mb-1">Advanced Mode</div>
                <div class="text-sm txt-secondary">Full access to all features and tools</div>
              </button>
            </div>
          </div>

          <!-- Theme Settings -->
          <div class="surface-card p-6">
            <h2 class="text-lg font-semibold txt-primary mb-2">Theme</h2>
            <p class="txt-secondary text-sm mb-4">Choose your color scheme</p>
            
            <div class="grid grid-cols-3 gap-3">
              <button
                @click="setTheme('light')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all',
                  theme === 'light'
                    ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                    : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50'
                ]"
              >
                <SunIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
                <div class="text-sm font-medium txt-primary">Light</div>
              </button>
              
              <button
                @click="setTheme('dark')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all',
                  theme === 'dark'
                    ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                    : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50'
                ]"
              >
                <MoonIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
                <div class="text-sm font-medium txt-primary">Dark</div>
              </button>
              
              <button
                @click="setTheme('system')"
                :class="[
                  'p-4 rounded-lg border-2 transition-all',
                  theme === 'system'
                    ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                    : 'border-light-border/30 dark:border-dark-border/20 hover:border-[var(--brand)]/50'
                ]"
              >
                <ComputerDesktopIcon class="w-6 h-6 mx-auto mb-2 txt-primary" />
                <div class="text-sm font-medium txt-primary">System</div>
              </button>
            </div>
          </div>

          <!-- Account Info -->
          <div class="surface-card p-6">
            <h2 class="text-lg font-semibold txt-primary mb-4">Account</h2>
            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium txt-secondary mb-1">Email</label>
                <div class="txt-primary">{{ authStore.user?.email || 'Not logged in' }}</div>
              </div>
              <div>
                <label class="block text-sm font-medium txt-secondary mb-1">User Level</label>
                <div class="txt-primary">{{ authStore.user?.userLevel || 'N/A' }}</div>
              </div>
            </div>
          </div>

          <!-- Logout -->
          <div class="surface-card p-6">
            <button
              @click="handleLogout"
              class="btn-primary px-6 py-2.5 rounded-lg w-full"
            >
              Logout
            </button>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAppModeStore } from '@/stores/appMode'
import { useTheme } from '@/composables/useTheme'
import MainLayout from '@/components/MainLayout.vue'
import { SunIcon, MoonIcon, ComputerDesktopIcon } from '@heroicons/vue/24/outline'

const router = useRouter()
const authStore = useAuthStore()
const appModeStore = useAppModeStore()
const { theme, setTheme } = useTheme()

const handleLogout = async () => {
  await authStore.logout()
  router.push('/login')
}
</script>

