/* ============================================================================
 *  dashboard.js - Helper de graficos para la vista Dashboard
 *
 *  Solo inicializa el namespace Dashboard con el registry de charts.
 *  La logica de cargar/renderizar esta en views.js (vista dashboard).
 * ========================================================================== */

window.Dashboard = {
    charts: {},

    // Helper para destruir todos los charts activos (al salir de la vista)
    destroyAll() {
        Object.keys(this.charts).forEach(key => {
            if (this.charts[key]) {
                try { this.charts[key].destroy(); } catch (e) {}
                delete this.charts[key];
            }
        });
    }
};

// Cargar Chart.js desde CDN si no esta disponible
if (typeof Chart === 'undefined') {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    s.onload = () => console.info('[Chart.js] cargado');
    s.onerror = () => console.warn('[Chart.js] no se pudo cargar desde CDN');
    document.head.appendChild(s);
}
