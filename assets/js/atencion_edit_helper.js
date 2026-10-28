/**
 * assets/js/atencion_edit_helper.js
 *
 * Agrega a App.views.tickets la capacidad de EDITAR atenciones desde
 * el detalle del ticket.
 *
 * CÓMO INSTALAR:
 *
 * 1. Cargar este archivo DESPUÉS de assets/js/views.js en index.php:
 *
 *      <script src="assets/js/app.js"></script>
 *      <script src="assets/js/views.js"></script>
 *      <script src="assets/js/atencion_edit_helper.js"></script>  <-- nuevo
 *      <script src="assets/js/geo_helper.js"></script>            <-- nuevo
 *
 * 2. En views.js, dentro de renderDetail(), en la sección donde se
 *    renderiza cada atención (alrededor de línea 1131), AGREGAR un
 *    botón "Editar" al lado del botón "Marcar como solucionadora".
 *
 *    Reemplazar este bloque:
 *      ${!esSolucionadora && puedeSolucionar && a.estado === 'COMPLETADO' ? `
 *          <div class="atencion-item__actions">
 *              <button class="btn btn-sm btn-success" data-marcar-solucionadora="${a.id_atencion}">
 *                  <i class="bi bi-check-circle"></i> Marcar como solucionadora
 *              </button>
 *          </div>` : ''}
 *
 *    Por este:
 *      <div class="atencion-item__actions">
 *          ${puedeRegistrarAtencion ? `
 *              <button class="btn btn-sm btn-secondary" data-editar-atencion="${a.id_atencion}">
 *                  <i class="bi bi-pencil"></i> Editar
 *              </button>` : ''}
 *          ${!esSolucionadora && puedeSolucionar && a.estado === 'COMPLETADO' ? `
 *              <button class="btn btn-sm btn-success" data-marcar-solucionadora="${a.id_atencion}">
 *                  <i class="bi bi-check-circle"></i> Marcar como solucionadora
 *              </button>` : ''}
 *      </div>
 *
 * 3. En views.js, dentro de renderDetail(), en la sección de listeners
 *    (alrededor de línea 1243), AGREGAR el handler del botón editar:
 *
 *      container.querySelectorAll('[data-editar-atencion]').forEach(button => {
 *          button.addEventListener('click', () => {
 *              const idAtencion = Number(button.dataset.editarAtencion);
 *              const atencion = atenciones.find(a => Number(a.id_atencion) === idAtencion);
 *              App.showEditAtencionModal(t, atencion, () => {
 *                  this.renderDetail(container, id);
 *              });
 *          });
 *      });
 *
 * 4. Este archivo define App.showEditAtencionModal() automáticamente.
 */

App.showEditAtencionModal = function(ticket, atencion, onSuccess) {
    const isNew = !atencion;
    const title = isNew
        ? `Nueva atención - ${ticket.nro_ticket}`
        : `Editar atención #${atencion.id_atencion} - ${ticket.nro_ticket}`;

    const a = atencion || {};

    App.showModal(title,
        `<form id="formAtencion">
            <div class="alert alert-info">
                <i class="bi bi-clock-history"></i>
                ${isNew
                    ? 'La fecha y hora de inicio se registrarán automáticamente con la hora del servidor.'
                    : 'Si cambia el estado a <strong>Completado</strong>, se recalculará la duración automáticamente.'}
            </div>

            <div class="form-group">
                <label class="form-label">Detalle del trabajo <span class="req">*</span></label>
                <textarea class="form-textarea" id="detalleAtencion" required
                    placeholder="Describa el trabajo realizado...">${App.escapeHtml(a.detalle_atencion || '')}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Diagnóstico</label>
                <textarea class="form-textarea" id="diagnosticoAtencion"
                    placeholder="Análisis técnico del problema...">${App.escapeHtml(a.diagnostico || '')}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Acciones ejecutadas</label>
                <textarea class="form-textarea" id="accionesAtencion"
                    placeholder="Pasos realizados durante la intervención...">${App.escapeHtml(a.acciones_ejecutadas || '')}</textarea>
            </div>

            ${a.solucion_aplicada || !isNew ? `
            <div class="form-group">
                <label class="form-label">Solución aplicada</label>
                <textarea class="form-textarea" id="solucionAtencion"
                    placeholder="Solución técnica aplicada...">${App.escapeHtml(a.solucion_aplicada || '')}</textarea>
            </div>` : ''}

            <div class="form-group">
                <label class="form-label">Resultado</label>
                <input type="text" class="form-input" id="resultadoAtencion"
                    value="${App.escapeHtml(a.resultado || '')}"
                    placeholder="Resultado final comprobado..." maxlength="500">
            </div>

            <div class="form-group">
                <label class="form-label">Estado de la atención</label>
                <select class="form-select" id="estadoAtencion">
                    <option value="EN_PROCESO"  ${a.estado === 'EN_PROCESO' ? 'selected' : ''}>En Proceso</option>
                    <option value="COMPLETADO"  ${a.estado === 'COMPLETADO' ? 'selected' : ''}>Completado</option>
                    <option value="PAUSADO"     ${a.estado === 'PAUSADO' ? 'selected' : ''}>Pausado</option>
                </select>
                <div class="alert alert-info" id="alertCompletado" style="display:none;margin-top:8px">
                    <i class="bi bi-info-circle"></i>
                    Al guardar con estado <strong>Completado</strong>, se calculará la duración
                    ${isNew ? 'y el ticket pasá a <strong>RESUELTO</strong> automáticamente' : ''}.
                </div>
            </div>
        </form>`,
        `<button class="btn" onclick="App.closeModal()">Cancelar</button>
         <button class="btn btn-primary" id="btnSaveAtencion">
             <i class="bi bi-check-lg"></i> ${isNew ? 'Guardar' : 'Actualizar'}
         </button>`);

    // Mostrar/ocultar alerta informativa al cambiar estado
    const estadoSel = document.getElementById('estadoAtencion');
    const alerta = document.getElementById('alertCompletado');
    estadoSel.addEventListener('change', (e) => {
        alerta.style.display = e.target.value === 'COMPLETADO' ? 'block' : 'none';
    });
    alerta.style.display = estadoSel.value === 'COMPLETADO' ? 'block' : 'none';

    // Handler del botón Guardar
    document.getElementById('btnSaveAtencion').addEventListener('click', async () => {
        const detalle = document.getElementById('detalleAtencion').value.trim();
        const estado = document.getElementById('estadoAtencion').value;
        if (!detalle) {
            App.toast('El detalle del trabajo es requerido', 'warning');
            return;
        }

        try {
            const body = {
                detalle_atencion: detalle,
                diagnostico: document.getElementById('diagnosticoAtencion').value || null,
                acciones_ejecutadas: document.getElementById('accionesAtencion').value || null,
                resultado: document.getElementById('resultadoAtencion').value || null,
                estado: estado,
            };

            // Solución solo si el campo existe en el modal
            const solInput = document.getElementById('solucionAtencion');
            if (solInput) body.solucion_aplicada = solInput.value || null;

            let result;
            if (isNew) {
                // POST /atenciones (crear nueva)
                body.id_ticket = ticket.id_ticket;
                body.fecha_inicio = null;
                body.hora_inicio  = null;
                body.fecha_fin    = null;
                body.hora_fin     = null;
                result = await App.request('/atenciones', { method: 'POST', body });
            } else {
                // PUT /atenciones/{id} (editar)
                result = await App.request(`/atenciones/${atencion.id_atencion}`, {
                    method: 'PUT', body
                });
            }

            App.closeModal();
            App.toast(isNew ? 'Atención registrada' : 'Atención actualizada', 'success');

            // Si es nueva y COMPLETADO, abrir modal de solución
            if (isNew && estado === 'COMPLETADO' && result.id_atencion) {
                setTimeout(() => {
                    App.views.tickets.showSolucionarModal(ticket, result.id_atencion);
                }, 200);
            } else if (onSuccess) {
                onSuccess();
            }
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    });
};

// Sobrescribir showAtencionModal para que use el helper unificado
App.views.tickets.showAtencionModal = function(ticket) {
    App.showEditAtencionModal(ticket, null, () => {
        this.renderDetail(document.getElementById('appView'), ticket.id_ticket);
    });
};
