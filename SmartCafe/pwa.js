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
        gap: 12px;
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.25);
        padding: 8px 18px 8px 10px;
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

      #smartcafe-install-btn .pwa-logo-wrapper {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        background: #ffffff;
        border-radius: 50%;
        padding: 2px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
        flex-shrink: 0;
      }

      #smartcafe-install-btn .pwa-app-img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: contain;
        background: #ffffff;
        display: block;
      }

      #smartcafe-install-btn .pwa-badge-icon {
        position: absolute;
        bottom: -2px;
        right: -3px;
        width: 14px;
        height: 14px;
        background: #38bdf8;
        color: #0f172a;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 8px;
        font-weight: 900;
        border: 1.5px solid #0f172a;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
      }

      #smartcafe-install-btn .pwa-install-text {
        white-space: nowrap;
        font-weight: 700;
        letter-spacing: -0.2px;
      }

      #smartcafe-install-btn .pwa-close {
        color: #94a3b8;
        font-size: 18px;
        margin-left: 4px;
        cursor: pointer;
        padding: 2px 6px;
        line-height: 1;
        transition: color 0.2s;
        border-radius: 50%;
      }

      #smartcafe-install-btn .pwa-close:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.1);
      }

      /* Manual Install Guidance Modal */
      #smartcafe-install-modal {
        position: fixed;
        inset: 0;
        z-index: 100000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: pwaFadeIn 0.3s ease-out;
      }
      .pwa-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
      }
      .pwa-modal-card {
        position: relative;
        z-index: 10;
        background: #0f172a;
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 24px;
        padding: 32px 28px;
        max-width: 420px;
        width: 100%;
        text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      }
      .pwa-modal-close {
        position: absolute;
        top: 14px;
        right: 16px;
        background: transparent;
        border: none;
        color: #94a3b8;
        font-size: 24px;
        cursor: pointer;
        padding: 4px 8px;
        line-height: 1;
      }
      .pwa-modal-close:hover { color: #ffffff; }
      .pwa-modal-logo {
        width: 72px;
        height: 72px;
        border-radius: 20px;
        background: #ffffff;
        border: 2px solid rgba(255, 255, 255, 0.25);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        margin: 0 auto 16px;
        display: block;
        object-fit: cover;
      }
      .pwa-modal-card h3 {
        margin: 0 0 8px;
        font-size: 20px;
        font-weight: 800;
        color: #ffffff;
      }
      .pwa-modal-card p {
        margin: 0 0 20px;
        font-size: 13px;
        color: #94a3b8;
        line-height: 1.5;
      }
      .pwa-modal-steps {
        display: flex;
        flex-direction: column;
        gap: 10px;
        text-align: left;
      }
      .pwa-modal-step {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 13px;
        color: #e2e9f0;
        line-height: 1.4;
        background: rgba(255, 255, 255, 0.05);
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.08);
      }
      .pwa-step-num {
        background: #38bdf8;
        color: #0f172a;
        font-weight: 800;
        font-size: 11px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-top: 1px;
      }
      @keyframes pwaFadeIn {
        from { opacity: 0; transform: scale(0.96); }
        to { opacity: 1; transform: scale(1); }
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

  // Helper to reliably resolve absolute asset URLs
  function getAppAssetUrl(relativePath) {
    const scripts = document.querySelectorAll('script[src*="pwa.js"]');
    if (scripts.length > 0) {
      const src = scripts[0].getAttribute('src');
      try {
        const url = new URL(src, window.location.href);
        return new URL(relativePath, url).href;
      } catch (e) {
        const cleanSrc = src.split('?')[0];
        const idx = cleanSrc.lastIndexOf('/');
        if (idx !== -1) {
          return cleanSrc.substring(0, idx + 1) + relativePath;
        }
      }
    }
    return relativePath;
  }

  // 3. Create or Show Install Button with Brand Image
  function showInstallButton() {
    // If running in standalone PWA, do not show install button
    const isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;

    if (isStandalone) return;

    let btn = document.getElementById('smartcafe-install-btn');
    if (!btn) {
      const logoUrl = getAppAssetUrl('logo.png');
      const iconFallback = getAppAssetUrl('icons/icon-192x192.png');

      btn = document.createElement('button');
      btn.id = 'smartcafe-install-btn';
      btn.type = 'button';
      btn.setAttribute('aria-label', 'Install BesKape Application');
      btn.innerHTML = `
        <div class="pwa-logo-wrapper">
          <img src="${logoUrl}" alt="BesKape App Logo" class="pwa-app-img" onerror="if(this.src!=='${iconFallback}') this.src='${iconFallback}';">
          <span class="pwa-badge-icon">
            <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 5v14M19 12l-7 7-7-7"/>
            </svg>
          </span>
        </div>
        <span class="pwa-install-text">Install BesKape App</span>
        <span class="pwa-close" title="Dismiss" id="pwa-dismiss-btn" aria-label="Dismiss">&times;</span>
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
              console.log('[BesKape PWA] User accepted installation prompt');
              btn.style.display = 'none';
            }
            deferredPrompt = null;
          });
        } else {
          // If deferredPrompt is unavailable, show friendly guidance modal with logo
          showManualInstallModal(logoUrl, iconFallback);
        }
      });
    }

    if (sessionStorage.getItem('smartcafe_pwa_dismissed') !== 'true') {
      btn.style.display = 'flex';
    }
  }

  function showManualInstallModal(logoUrl, iconFallback) {
    let modal = document.getElementById('smartcafe-install-modal');
    if (modal) {
      modal.remove();
    }
    modal = document.createElement('div');
    modal.id = 'smartcafe-install-modal';
    modal.innerHTML = `
      <div class="pwa-modal-backdrop"></div>
      <div class="pwa-modal-card">
        <button class="pwa-modal-close" id="pwa-modal-close-btn">&times;</button>
        <img src="${logoUrl}" alt="BesKape App" class="pwa-modal-logo" onerror="if(this.src!=='${iconFallback}') this.src='${iconFallback}';">
        <h3>Install BesKape App</h3>
        <p>Install BesKape on your device for quick offline access, full-screen mode, and a fast native experience.</p>
        <div class="pwa-modal-steps">
          <div class="pwa-modal-step">
            <span class="pwa-step-num">1</span>
            <span>On <strong>Desktop (Chrome/Edge)</strong>, click the <strong>Install</strong> icon on the right side of the address bar.</span>
          </div>
          <div class="pwa-modal-step">
            <span class="pwa-step-num">2</span>
            <span>On <strong>Android</strong>, tap the browser menu (<strong>&vellip;</strong>) and choose <strong>Install app</strong> or <strong>Add to Home screen</strong>.</span>
          </div>
          <div class="pwa-modal-step">
            <span class="pwa-step-num">3</span>
            <span>On <strong>iOS (Safari)</strong>, tap the <strong>Share</strong> button and select <strong>Add to Home Screen</strong>.</span>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    modal.querySelector('.pwa-modal-backdrop').addEventListener('click', () => modal.remove());
    modal.querySelector('#pwa-modal-close-btn').addEventListener('click', () => modal.remove());
  }

  // 4. Register Service Worker
  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      console.log('[BesKape PWA] Service Worker not supported in this browser.');
      return;
    }

    window.addEventListener('load', () => {
      // Relative path to sw.js ensures proper scope whether on / or /SmartCafe/
      navigator.serviceWorker
        .register('sw.js', { scope: './' })
        .then((registration) => {
          console.log('[BesKape PWA] Service Worker registered with scope:', registration.scope);

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

                showToast('New BesKape version available!', 'update', updateBtn);
              }
            });
          });
        })
        .catch((error) => {
          console.warn('[BesKape PWA] Service Worker registration failed:', error);
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
      console.log('[BesKape PWA] Application was installed');
      deferredPrompt = null;
      const btn = document.getElementById('smartcafe-install-btn');
      if (btn) btn.style.display = 'none';
      showToast('BesKape installed successfully!', 'online');
    });

    // Network status listeners
    window.addEventListener('offline', () => {
      showToast('You are offline. Browsing in cached mode.', 'offline');
    });

    window.addEventListener('online', () => {
      hideToast();
      showToast('Back online! Reconnected to BesKape.', 'online');
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
