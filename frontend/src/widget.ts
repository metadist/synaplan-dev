import { createApp } from 'vue'
import ChatWidget from './components/widgets/ChatWidget.vue'

interface WidgetConfig {
  widgetId: string
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  primaryColor?: string
  iconColor?: string
  defaultTheme?: 'light' | 'dark'
  autoOpen?: boolean
  autoMessage?: string
  apiUrl?: string
}

/**
 * Synaplan Chat Widget - Standalone Bundle
 * 
 * Usage:
 * <script src="https://your-domain.com/widget.js"></script>
 * <script>
 *   SynaplanWidget.init({
 *     widgetId: 'wdg_abc123...',
 *     position: 'bottom-right',
 *     primaryColor: '#007bff',
 *     autoOpen: false
 *   });
 * </script>
 */
class SynaplanWidget {
  private config: WidgetConfig | null = null
  private app: any = null
  private container: HTMLElement | null = null

  /**
   * Initialize the widget
   */
  init(config: WidgetConfig) {
    if (!config.widgetId) {
      console.error('Synaplan Widget: widgetId is required')
      return
    }

    this.config = {
      position: 'bottom-right',
      primaryColor: '#007bff',
      iconColor: '#ffffff',
      defaultTheme: 'light',
      autoOpen: false,
      autoMessage: 'Hello! How can I help you today?',
      apiUrl: import.meta.env.VITE_API_URL || 'http://localhost:8000',
      ...config
    }

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.mount())
    } else {
      this.mount()
    }
  }

  /**
   * Mount the widget to the DOM
   */
  private mount() {
    if (!this.config) return

    // Create container
    this.container = document.createElement('div')
    this.container.id = 'synaplan-widget-root'
    document.body.appendChild(this.container)

    // Create Vue app
    this.app = createApp(ChatWidget, {
      widgetId: this.config.widgetId,
      position: this.config.position,
      primaryColor: this.config.primaryColor,
      iconColor: this.config.iconColor,
      defaultTheme: this.config.defaultTheme,
      autoOpen: this.config.autoOpen,
      autoMessage: this.config.autoMessage,
      apiUrl: this.config.apiUrl,
      isPreview: false
    })

    this.app.mount(this.container)

    console.log('✅ Synaplan Widget loaded successfully')
  }

  /**
   * Destroy the widget
   */
  destroy() {
    if (this.app) {
      this.app.unmount()
      this.app = null
    }

    if (this.container && this.container.parentNode) {
      this.container.parentNode.removeChild(this.container)
      this.container = null
    }

    this.config = null
  }

  /**
   * Open the widget programmatically
   */
  open() {
    // Emit custom event that ChatWidget can listen to
    window.dispatchEvent(new CustomEvent('synaplan-widget-open'))
  }

  /**
   * Close the widget programmatically
   */
  close() {
    window.dispatchEvent(new CustomEvent('synaplan-widget-close'))
  }
}

// Expose to global scope
const widgetInstance = new SynaplanWidget()

// @ts-ignore
window.SynaplanWidget = widgetInstance

export default widgetInstance

