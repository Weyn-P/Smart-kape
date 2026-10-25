/**
 * SmartCafe Progressive Web App (PWA) Client Controller
 * Handles Service Worker registration, install prompts, offline status, and update notifications.
 */

(function () {
  'use strict';

  let deferredPrompt = null;
  let newWorker = null;

  // 1. Inject Styles for PWA Components
  function injectPwaStyles() {
    if (document.getElementById('smartcafe-pwa-styles')) return;

    const style = document.createElement('style');
    style.id = 'smartcafe-pwa-styles';
    style.textContent = `
      /* PWA Floating Install Button */
      #smartcafe-install-btn {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 99999;
        display: none;
        align-items: center;
        gap: 10px;
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.2);
        padding: 12px 20px;
        border-radius: 9999px;
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        animation: pwaSlideUp 0.5s ease-out forwards;
      }

      #smartcafe-install-btn:hover {
        transform: translateY(-2px) scale(1.03);
        background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        box-shadow: 0 16px 32px -4px rgba(15, 23, 42, 0.6);
        border-color: rgba(255, 255, 255, 0.35);
      }

      #smartcafe-install-btn .pwa-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        background: rgba(255, 255, 255, 0.15);
        border-radius: 50%;
        color: #38bdf8;
        font-size: 13px;
      }

      #smartcafe-install-btn .pwa-close {
        color: #94a3b8;
        font-size: 16px;
        margin-left: 6px;
        cursor: pointer;
        padding: 2px 4px;
        line-height: 1;
        transition: color 0.2s;
      }

      #smartcafe-install-btn .pwa-close:hover {
        color: #ffffff;
      }

      /* PWA Status Toasts (Offline, Online, Updates) */
      .smartcafe-toast {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%) translateY(-100px);
        z-index: 100000;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 24px;
        border-radius: 16px;
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        font-size: 14px;
        font-weight: 600;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s;
        opacity: 0;
        pointer-events: auto;
        max-width: 90vw;
      }

      .smartcafe-toast.show {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
      }

      .smartcafe-toast-offline {
        background: rgba(239, 68, 68, 0.9);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.2);
      }

      .smartcafe-toast-online {
        background: rgba(16, 185, 129, 0.9);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.2);
      }

      .smartcafe-toast-update {
        background: rgba(15, 23, 42, 0.95);
        color: #ffffff;
        border: 1px solid rgba(56, 189, 248, 0.4);
      }

      .smartcafe-toast-btn {
        background: #38bdf8;
        color: #0f172a;
        border: none;
        border-radius: 8px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        margin-left: 8px;
        transition: background 0.2s;
      }

      .smartcafe-toast-btn:hover {
        background: #7dd3fc;
      }

      @keyframes pwaSlideUp {
        from {
          opacity: 0;
          transform: translateY(20px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      @media (max-width: 640px) {
        #smartcafe-install-btn {
          bottom: 16px;
          right: 16px;
          padding: 10px 16px;
          font-size: 13px;
        }
      }
    `;
    document.head.appendChild(style);
  }

  // 2. Display Toast Message
  function showToast(message, type = 'info', actionBtn = null) {
    let toast = document.getElementById('smartcafe-pwa-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'smartcafe-pwa-toast';
      document.body.appendChild(toast);
    }

    toast.className = `smartcafe-toast smartcafe-toast-${type}`;
    toast.innerHTML = '';

    const iconSpan = document.createElement('span');
    if (type === 'offline') {
      iconSpan.innerHTML = '<i class="fa-solid fa-wifi-slash"></i>';
    } else if (type === 'online') {
      iconSpan.innerHTML = '<i class="fa-solid fa-wifi"></i>';
    } else {
      iconSpan.innerHTML = '<i class="fa-solid fa-circle-info"></i>';
    }
    toast.appendChild(iconSpan);

    const textSpan = document.createElement('span');
    textSpan.textContent = message;
    toast.appendChild(textSpan);

    if (actionBtn) {
      toast.appendChild(actionBtn);
    }

    // Force reflow
    void toast.offsetHeight;
    toast.classList.add('show');

    if (type !== 'update' && type !== 'offline') {
      setTimeout(() => {
        toast.classList.remove('show');
      }, 3500);
    }
  }

  function hideToast() {
    const toast = document.getElementById('smartcafe-pwa-toast');
    if (toast) {
      toast.classList.remove('show');
    }
  }

  // 3. Create or Show Install Button
  function showInstallButton() {
    // If running in standalone PWA, do not show install button
    const isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;

    if (isStandalone) return;

    let btn = document.getElementById('smartcafe-install-btn');
    if (!btn) {
      btn = document.createElement('button');
      btn.id = 'smartcafe-install-btn';
      btn.type = 'button';
      btn.setAttribute('aria-label', 'Install SmartCafe Application');
      btn.innerHTML = `
        <span class="pwa-icon"><i class="fa-solid fa-arrow-down-to-bracket"></i></span>
        <span>Install SmartCafe App</span>
        <span class="pwa-close" title="Dismiss" id="pwa-dismiss-btn">&times;</span>
      `;
      document.body.appendChild(btn);

      btn.addEventListener('click', (e) => {
        if (e.target.id === 'pwa-dismiss-btn' || e.target.classList.contains('pwa-close')) {
          e.stopPropagation();
          btn.style.display = 'none';
          sessionStorage.setItem('smartcafe_pwa_dismissed', 'true');
          return;
        }

        if (deferredPrompt) {
          deferredPrompt.prompt();
          deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
              console.log('[SmartCafe PWA] User accepted installation prompt');
              btn.style.display = 'none';
            }
            deferredPrompt = null;
          });
        }
      });
    }

    if (sessionStorage.getItem('smartcafe_pwa_dismissed') !== 'true') {
      btn.style.display = 'flex';
    }
  }

  // 4. Register Service Worker
  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      console.log('[SmartCafe PWA] Service Worker not supported in this browser.');
      return;
    }

    window.addEventListener('load', () => {
      // Relative path to sw.js ensures proper scope whether on / or /SmartCafe/
      navigator.serviceWorker
        .register('sw.js', { scope: './' })
        .then((registration) => {
          console.log('[SmartCafe PWA] Service Worker registered with scope:', registration.scope);

          // Check for Service Worker updates
          registration.addEventListener('updatefound', () => {
            newWorker = registration.installing;
            newWorker.addEventListener('statechange', () => {
              if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // New update is ready
                const updateBtn = document.createElement('button');
                updateBtn.className = 'smartcafe-toast-btn';
                updateBtn.textContent = 'Update';
                updateBtn.addEventListener('click', () => {
                  newWorker.postMessage({ action: 'skipWaiting' });
                  window.location.reload();
                });

                showToast('New SmartCafe version available!', 'update', updateBtn);
              }
            });
          });
        })
        .catch((error) => {
          console.warn('[SmartCafe PWA] Service Worker registration failed:', error);
        });

      // Reload page when new service worker takes over
      let refreshing = false;
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!refreshing) {
          refreshing = true;
          window.location.reload();
        }
      });
    });
  }

  // 5. Setup Event Listeners
  function initPwa() {
    injectPwaStyles();

    // Listen for beforeinstallprompt event
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      showInstallButton();
    });

    // App installed event
    window.addEventListener('appinstalled', () => {
      console.log('[SmartCafe PWA] Application was installed');
      deferredPrompt = null;
      const btn = document.getElementById('smartcafe-install-btn');
      if (btn) btn.style.display = 'none';
      showToast('SmartCafe installed successfully!', 'online');
    });

    // Network status listeners
    window.addEventListener('offline', () => {
      showToast('You are offline. Browsing in cached mode.', 'offline');
    });

    window.addEventListener('online', () => {
      hideToast();
      showToast('Back online! Reconnected to SmartCafe.', 'online');
    });

    // Initial offline check
    if (!navigator.onLine) {
      showToast('You are offline. Browsing in cached mode.', 'offline');
    }

    registerServiceWorker();
  }

  // Initialize once DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPwa);
  } else {
    initPwa();
  }
})();
