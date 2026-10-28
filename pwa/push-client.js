/* ============================================================================
 *  pwa/push-client.js - Suscripcion Web Push del lado del navegador
 *
 *  - Solicita permiso al usuario
 *  - Suscribe el navegador con el servidor VAPID
 *  - Envia la suscripcion al backend para almacenarla en pwa_subscription
 * ========================================================================== */

const PushClient = {
  // Clave publica VAPID (debe venir del backend en /api/v1/auth/vapid-public-key)
  vapidPublicKey: null,

  async init() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      console.info('[PWA] Push no soportado en este navegador');
      return;
    }
    // Esperar a que el SW este activo
    await navigator.serviceWorker.ready;
    // Cargar la clave publica VAPID
    try {
      const r = await fetch('/api/v1/auth/vapid-public-key', { credentials: 'include' });
      const j = await r.json();
      this.vapidPublicKey = j.data?.publicKey;
    } catch (err) {
      console.warn('[PWA] No se pudo obtener clave VAPID', err);
      return;
    }
    // Si ya esta suscripto, no hacer nada
    const existing = await this.getExistingSubscription();
    if (existing) {
      console.info('[PWA] Ya suscripto a notificaciones push');
      return;
    }
    // Auto-suscribir si el permiso ya fue concedido antes
    if (Notification.permission === 'granted') {
      await this.subscribe();
    }
  },

  async requestPermissionAndSubscribe() {
    if (!('Notification' in window)) {
      alert('Tu navegador no soporta notificaciones');
      return false;
    }
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      alert('Las notificaciones fueron bloqueadas. Puedes habilitarlas en la configuracion del navegador.');
      return false;
    }
    return await this.subscribe();
  },

  async subscribe() {
    if (!this.vapidPublicKey) {
      console.error('[PWA] Falta clave VAPID publica');
      return false;
    }
    const sw = await navigator.serviceWorker.ready;
    const sub = await sw.pushManager.subscribe({
      userVisibleOnly: true,                          // siempre visibles
      applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey)
    });
    // Enviar al backend
    const r = await fetch('/api/v1/notificaciones/push/subscribe', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        endpoint:   sub.endpoint,
        p256dh_key: btoa(String.fromCharCode.apply(null, new Uint8Array(sub.getKey('p256dh')))),
        auth_key:   btoa(String.fromCharCode.apply(null, new Uint8Array(sub.getKey('auth')))),
      })
    });
    if (r.ok) {
      console.info('[PWA] Suscripcion push registrada');
      return true;
    }
    return false;
  },

  async unsubscribe() {
    const sub = await this.getExistingSubscription();
    if (!sub) return;
    await sub.unsubscribe();
    // TODO: avisar al backend para desactivar
  },

  async getExistingSubscription() {
    const sw = await navigator.serviceWorker.ready;
    return await sw.pushManager.getSubscription();
  },

  // Utilidad para decodificar clave VAPID
  urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const out = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) out[i] = rawData.charCodeAt(i);
    return out;
  }
};

// Exponer globalmente
window.PushClient = PushClient;

// Inicializar cuando la pagina carga (si el usuario esta logueado)
if (document.readyState !== 'loading') {
  PushClient.init();
} else {
  document.addEventListener('DOMContentLoaded', () => PushClient.init());
}
