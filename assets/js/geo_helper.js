/**
 * assets/js/geo_helper.js
 *
 * Helper para filtros geográficos en cascada:
 *   País → Departamento → Provincia → Distrito
 *
 * Carga una sola vez todos los datos del país seleccionado y luego
 * filtra en el cliente (mucho más rápido que N queries al servidor).
 *
 * USO:
 *
 *   <div id="geoFilter"></div>
 *
 *   <script>
 *     GeoHelper.mount('geoFilter', {
 *       id_pais: 1,
 *       onDivisionSelected: (division, ruta) => {
 *         console.log('Seleccionado:', division, 'Ruta:', ruta);
 *       }
 *     });
 *   </script>
 *
 * TAMBIÉN se puede usar como modal de búsqueda:
 *
 *   GeoHelper.openModal({ id_pais: 1 }).then(d => console.log(d));
 */

const GeoHelper = {

    /* Caché por país: una sola petición trae deptos+provincias+distritos */
    cache: {},

    /**
     * Carga la jerarquía completa de un país.
     * Devuelve { departamentos, provincias, distritos }.
     */
    async loadPais(idPais) {
        if (!idPais) return { departamentos: [], provincias: [], distritos: [] };
        if (this.cache[idPais]) return this.cache[idPais];

        try {
            const data = await App.request(`/geografia/cascada?id_pais=${idPais}`);
            this.cache[idPais] = data;
            return data;
        } catch (e) {
            console.error('[GeoHelper] loadPais error:', e);
            return { departamentos: [], provincias: [], distritos: [] };
        }
    },

    /**
     * Pinta 3 selects en cascada dentro del contenedor indicado.
     *
     * Opciones:
     *   id_pais (int): País del que cargar las divisiones
     *   selected (object): { id_departamento, id_provincia, id_distrito } valores pre-seleccionados
     *   onDivisionSelected (function): callback(d, ruta) cuando se selecciona un distrito
     *   showDistrito (bool): si false, termina en provincia (default true)
     *   labels (object): sobreescribir labels { depto:'Departamento', prov:'Provincia', dist:'Distrito' }
     */
    async mount(containerId, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const labels = Object.assign({
            depto: 'Departamento',
            prov:  'Provincia',
            dist:  'Distrito',
        }, options.labels || {});

        container.innerHTML = `
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">${labels.depto}</label>
                    <select class="form-select" id="${containerId}_depto">
                        <option value="">— Todos —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">${labels.prov}</label>
                    <select class="form-select" id="${containerId}_prov" disabled>
                        <option value="">— Todas —</option>
                    </select>
                </div>
                ${options.showDistrito !== false ? `
                <div class="form-group">
                    <label class="form-label">${labels.dist}</label>
                    <select class="form-select" id="${containerId}_dist" disabled>
                        <option value="">— Todos —</option>
                    </select>
                </div>` : ''}
            </div>
        `;

        const data = await this.loadPais(options.id_pais);

        const selDepto = container.querySelector(`#${containerId}_depto`);
        const selProv  = container.querySelector(`#${containerId}_prov`);
        const selDist  = container.querySelector(`#${containerId}_dist`);

        // Llenar departamentos
        data.departamentos.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id_division;
            opt.textContent = d.nombre;
            selDepto.appendChild(opt);
        });

        // Pre-seleccionar
        if (options.selected?.id_departamento) {
            selDepto.value = options.selected.id_departamento;
            this._fillProvincias(selProv, selDist, data, options.selected.id_departamento);
            if (options.selected.id_provincia) {
                selProv.value = options.selected.id_provincia;
                if (selDist) this._fillDistritos(selDist, data, options.selected.id_provincia);
                if (options.selected.id_distrito) selDist.value = options.selected.id_distrito;
            }
        }

        // Handlers en cascada
        selDepto.addEventListener('change', () => {
            const idDepto = parseInt(selDepto.value) || 0;
            this._fillProvincias(selProv, selDist, data, idDepto);
            this._notify(selDepto, selProv, selDist, data, options);
        });
        if (selProv) {
            selProv.addEventListener('change', () => {
                const idProv = parseInt(selProv.value) || 0;
                if (selDist) this._fillDistritos(selDist, data, idProv);
                this._notify(selDepto, selProv, selDist, data, options);
            });
        }
        if (selDist) {
            selDist.addEventListener('change', () => {
                this._notify(selDepto, selProv, selDist, data, options);
            });
        }
    },

    _fillProvincias(selProv, selDist, data, idDepto) {
        if (!selProv) return;
        selProv.innerHTML = '<option value="">— Todas —</option>';
        if (selDist) {
            selDist.innerHTML = '<option value="">— Todos —</option>';
            selDist.disabled = true;
        }
        if (!idDepto) {
            selProv.disabled = true;
            return;
        }
        const provincias = data.provincias.filter(p => parseInt(p.id_departamento) === idDepto);
        provincias.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id_division;
            opt.textContent = p.nombre;
            selProv.appendChild(opt);
        });
        selProv.disabled = provincias.length === 0;
    },

    _fillDistritos(selDist, data, idProv) {
        if (!selDist) return;
        selDist.innerHTML = '<option value="">— Todos —</option>';
        if (!idProv) {
            selDist.disabled = true;
            return;
        }
        const distritos = data.distritos.filter(d => parseInt(d.id_provincia) === idProv);
        distritos.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id_division;
            opt.textContent = d.nombre;
            selDist.appendChild(opt);
        });
        selDist.disabled = distritos.length === 0;
    },

    _notify(selDepto, selProv, selDist, data, options) {
        if (!options.onDivisionSelected) return;

        const idDepto = parseInt(selDepto.value) || null;
        const idProv  = selProv ? (parseInt(selProv.value) || null) : null;
        const idDist  = selDist ? (parseInt(selDist.value) || null) : null;

        // Si hay distrito, es la división final. Si no, provincia. Si no, depto.
        const idDivision = idDist || idProv || idDepto;
        if (!idDivision) {
            options.onDivisionSelected(null, null);
            return;
        }

        const deptoNombre = data.departamentos.find(d => parseInt(d.id_division) === idDepto)?.nombre || '';
        const provNombre  = idProv ? (data.provincias.find(p => parseInt(p.id_division) === idProv)?.nombre || '') : '';
        const distNombre  = idDist ? (data.distritos.find(d => parseInt(d.id_division) === idDist)?.nombre || '') : '';

        const ruta = [deptoNombre, provNombre, distNombre].filter(Boolean).join(' > ');
        const tipo = idDist ? 'distrito' : (idProv ? 'provincia' : 'departamento');

        options.onDivisionSelected({
            id_division: idDivision,
            tipo: tipo,
            id_departamento: idDepto,
            id_provincia: idProv,
            id_distrito: idDist,
            nombre: distNombre || provNombre || deptoNombre,
        }, ruta);
    },

    /**
     * Devuelve la ruta completa de una división.
     * Útil para mostrar en el detalle de un ticket/empresa.
     */
    async getRuta(idDivision) {
        if (!idDivision) return null;
        try {
            return await App.request(`/geografia/ruta?id_division=${idDivision}`);
        } catch (e) {
            return null;
        }
    },

    /**
     * Abre un modal para seleccionar una división.
     * Devuelve Promise<{id_division, tipo, nombre, ruta}>.
     */
    openModal(options = {}) {
        return new Promise((resolve) => {
            const modalId = 'geoModal_' + Date.now();

            App.showModal('Seleccionar ubicación', `
                <div id="${modalId}" class="geo-modal">
                    <div class="form-group">
                        <label class="form-label">País</label>
                        <select class="form-select" id="${modalId}_pais">
                            <option value="">Cargando...</option>
                        </select>
                    </div>
                    <div id="${modalId}_filters"></div>
                </div>
            `, `
                <button class="btn" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" id="${modalId}_ok" disabled>
                    <i class="bi bi-check-lg"></i> Aceptar
                </button>
            `);

            let selected = null;

            // Cargar países
            fetch('/api/v1/geografia/paises', { credentials: 'include' })
                .then(r => r.json())
                .then(j => {
                    const sel = document.getElementById(`${modalId}_pais`);
                    sel.innerHTML = '<option value="">— Seleccione —</option>' +
                        j.data.map(p => `<option value="${p.id_pais}">${App.escapeHtml(p.nombre)}</option>`).join('');
                    if (options.id_pais) {
                        sel.value = options.id_pais;
                        sel.dispatchEvent(new Event('change'));
                    }
                });

            document.getElementById(`${modalId}_pais`).addEventListener('change', async (e) => {
                const idPais = parseInt(e.target.value) || 0;
                if (!idPais) return;
                await this.mount(`${modalId}_filters`, {
                    id_pais: idPais,
                    onDivisionSelected: (d, ruta) => {
                        selected = d ? { ...d, ruta } : null;
                        document.getElementById(`${modalId}_ok`).disabled = !d;
                    }
                });
            });

            document.getElementById(`${modalId}_ok`).addEventListener('click', () => {
                if (selected) {
                    App.closeModal();
                    resolve(selected);
                }
            });
        });
    }
};

// Exponer globalmente
window.GeoHelper = GeoHelper;
