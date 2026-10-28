/**
 * assets/js/configuracion_emisoras_logo_v2.js
 * ----------------------------------------------------------------------------
 * UI mejorada para la gestión del logo de la empresa emisora.
 *
 * Funcionalidades:
 *   - Modal con upload de logo (drag & drop o click)
 *   - Vista previa en tiempo real
 *   - Validación de tipo (PNG/JPG/SVG) y tamaño (máx 2 MB)
 *   - Botón "Eliminar logo"
 *   - Persistencia en base de datos (via API)
 *
 * INCLUSIÓN:
 *   Cargar DESPUÉS de cotizaciones_mejoras.js:
 *   <script src="/assets/js/configuracion_emisoras_logo_v2.js"></script>
 *
 * Expone la función global App.modalSubirLogoV2(idEmisora, nombre) que
 * reemplaza a App.modalSubirLogo si existe.
 */

(function () {
    'use strict';

    if (!window.App) return;

    /**
     * Abre el modal para gestionar el logo de una empresa emisora.
     * @param {number} idEmisora
     * @param {string} nombreEmisora
     */
    App.modalSubirLogoV2 = async function (idEmisora, nombreEmisora) {
        if (!idEmisora) {
            App.toast('Seleccione una emisora', 'warning');
            return;
        }

        const title = `Logo de la empresa emisora · ${nombreEmisora || ('#' + idEmisora)}`;

        const bodyHtml = `
            <div id="logoModalContent">
                <div style="display:grid;grid-template-columns:200px 1fr;gap:14px;">
                    <div>
                        <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">
                            Vista previa actual
                        </label>
                        <div id="logoPreviewBox" style="
                            width:200px;height:120px;border:2px dashed #bdc3c7;
                            display:flex;align-items:center;justify-content:center;
                            background:#fafbfc;border-radius:4px;overflow:hidden;">
                            <div class="spinner"></div>
                        </div>
                        <div id="logoSizeInfo" style="font-size:10px;color:#7f8c8d;margin-top:4px;"></div>
                    </div>
                    <div>
                        <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">
                            Subir nuevo logo
                        </label>
                        <input type="file" id="logoFileInput"
                               accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp"
                               style="display:block;margin-bottom:8px;width:100%;font-size:11px;">
                        <div style="font-size:10px;color:#7f8c8d;margin-bottom:8px;">
                            Formatos: PNG, JPG, SVG, GIF, WebP · Máximo 2 MB
                        </div>

                        <div id="logoDropZone" style="
                            border:2px dashed #bdc3c7;padding:18px;text-align:center;
                            color:#95a5a6;font-size:11px;border-radius:4px;cursor:pointer;
                            transition:all 0.2s;">
                            📁 Arrastre el archivo aquí o haga clic para seleccionar
                        </div>

                        <div style="margin-top:14px;display:flex;gap:6px;">
                            <button class="btn btn-primary" id="btnUploadLogo" disabled>
                                <i class="bi bi-upload"></i> Subir logo
                            </button>
                            <button class="btn btn-danger" id="btnDeleteLogo">
                                <i class="bi bi-trash"></i> Eliminar logo
                            </button>
                            <button class="btn" id="btnDownloadLogo">
                                <i class="bi bi-download"></i> Descargar
                            </button>
                        </div>

                        <div id="logoStatusMsg" style="margin-top:10px;font-size:11px;"></div>
                    </div>
                </div>
            </div>`;

        const footerHtml = `
            <button class="btn" onclick="App.closeModal()">Cerrar</button>`;

        App.showModal(title, bodyHtml, footerHtml);

        let selectedFile = null;
        const fileInput = document.getElementById('logoFileInput');
        const dropZone  = document.getElementById('logoDropZone');
        const uploadBtn = document.getElementById('btnUploadLogo');
        const statusMsg = document.getElementById('logoStatusMsg');

        /* Cargar el logo actual */
        const loadCurrentLogo = async () => {
            try {
                const r = await App.request(`/cotizaciones_emisora/${idEmisora}/logo`);
                const box = document.getElementById('logoPreviewBox');
                const sizeInfo = document.getElementById('logoSizeInfo');
                if (r.data && r.data.logo_base64) {
                    box.innerHTML = `<img src="${r.data.logo_base64}" style="max-width:100%;max-height:100%;object-fit:contain;">`;
                    const bytes = Math.round((r.data.logo_base64.length * 3) / 4);
                    sizeInfo.textContent = `Tamaño aprox: ${(bytes / 1024).toFixed(1)} KB`;
                } else {
                    box.innerHTML = `<span style="color:#bdc3c7;font-size:11px;">(Sin logo cargado)</span>`;
                    sizeInfo.textContent = '';
                }
            } catch (e) {
                document.getElementById('logoPreviewBox').innerHTML =
                    `<span style="color:#dc3545;font-size:11px;">Error al cargar</span>`;
            }
        };

        await loadCurrentLogo();

        /* Validar archivo seleccionado */
        const validateFile = (file) => {
            if (!file) return 'No se seleccionó archivo';
            const allowedTypes = ['image/png','image/jpeg','image/jpg','image/svg+xml','image/gif','image/webp'];
            if (!allowedTypes.includes(file.type)) {
                return `Tipo no permitido: ${file.type}. Use PNG, JPG, SVG, GIF o WebP.`;
            }
            if (file.size > 2 * 1024 * 1024) {
                return `Archivo demasiado grande: ${(file.size/1024/1024).toFixed(2)} MB. Máximo 2 MB.`;
            }
            return null;
        };

        const handleFile = (file) => {
            const err = validateFile(file);
            if (err) {
                statusMsg.innerHTML = `<span style="color:#dc3545;">⚠ ${err}</span>`;
                uploadBtn.disabled = true;
                selectedFile = null;
                return;
            }
            selectedFile = file;
            statusMsg.innerHTML = `<span style="color:#28a745;">✓ ${App.escapeHtml(file.name)} (${(file.size/1024).toFixed(1)} KB)</span>`;
            uploadBtn.disabled = false;
            /* Preview */
            const reader = new FileReader();
            reader.onload = (e) => {
                document.getElementById('logoPreviewBox').innerHTML =
                    `<img src="${e.target.result}" style="max-width:100%;max-height:100%;object-fit:contain;">`;
            };
            reader.readAsDataURL(file);
        };

        fileInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files[0]) handleFile(e.target.files[0]);
        });

        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.style.background = '#e3f2fd';
            dropZone.style.borderColor = '#1976d2';
        });
        dropZone.addEventListener('dragleave', () => {
            dropZone.style.background = '';
            dropZone.style.borderColor = '#bdc3c7';
        });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.style.background = '';
            dropZone.style.borderColor = '#bdc3c7';
            if (e.dataTransfer.files && e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
        });

        /* Subir */
        uploadBtn.addEventListener('click', async () => {
            if (!selectedFile) return;
            uploadBtn.disabled = true;
            uploadBtn.textContent = 'Subiendo...';
            try {
                const reader = new FileReader();
                reader.onload = async (e) => {
                    try {
                        /* e.target.result = "data:image/png;base64,XXXX" */
                        await App.request(`/cotizaciones_emisora/${idEmisora}/logo`, {
                            method: 'POST',
                            body: { logo_base64: e.target.result }
                        });
                        App.toast('Logo actualizado correctamente', 'success');
                        statusMsg.innerHTML = `<span style="color:#28a745;">✓ Logo actualizado</span>`;
                        selectedFile = null;
                        uploadBtn.textContent = 'Subir logo';
                        uploadBtn.disabled = true;
                        fileInput.value = '';
                        await loadCurrentLogo();
                        /* Refrescar vista de emisoras si está visible */
                        if (App.views['cotizacion-emisoras'] && App.views['cotizacion-emisoras'].load) {
                            App.views['cotizacion-emisoras'].load();
                        }
                    } catch (err) {
                        App.toast('Error: ' + err.message, 'error');
                        uploadBtn.disabled = false;
                        uploadBtn.textContent = 'Subir logo';
                    }
                };
                reader.readAsDataURL(selectedFile);
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Subir logo';
            }
        });

        /* Eliminar */
        document.getElementById('btnDeleteLogo').addEventListener('click', async () => {
            if (!confirm('¿Eliminar el logo de esta emisora?')) return;
            try {
                await App.request(`/cotizaciones_emisora/${idEmisora}/logo`, { method: 'DELETE' });
                App.toast('Logo eliminado', 'success');
                await loadCurrentLogo();
                if (App.views['cotizacion-emisoras'] && App.views['cotizacion-emisoras'].load) {
                    App.views['cotizacion-emisoras'].load();
                }
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
            }
        });

        /* Descargar */
        document.getElementById('btnDownloadLogo').addEventListener('click', async () => {
            try {
                const r = await App.request(`/cotizaciones_emisora/${idEmisora}/logo`);
                if (r.data && r.data.logo_base64) {
                    const a = document.createElement('a');
                    a.href = r.data.logo_base64;
                    a.download = `logo_emisora_${idEmisora}.png`;
                    a.click();
                } else {
                    App.toast('No hay logo para descargar', 'warning');
                }
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
            }
        });
    };

    /* Reemplazar la función anterior si existe */
    App.modalSubirLogo = App.modalSubirLogoV2;

    console.log('[configuracion_emisoras_logo_v2] Cargado');
})();
