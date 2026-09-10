document.addEventListener('DOMContentLoaded', () => {
    // Navigation
    const navItems = document.querySelectorAll('.nav-item');
    const sections = document.querySelectorAll('.view-section');

    navItems.forEach(item => {
        item.addEventListener('click', () => {
            navItems.forEach(i => i.classList.remove('active'));
            sections.forEach(s => s.classList.remove('active'));
            
            item.classList.add('active');
            const target = item.getAttribute('data-target');
            document.getElementById(target).classList.add('active');
            
            if (target === 'dashboard') loadStatus();
            if (target === 'config') loadConfig();
        });
    });

    // Toast Notification
    const toast = document.getElementById('toast');
    const toastIcon = document.getElementById('toastIcon');
    const toastMessage = document.getElementById('toastMessage');

    const showToast = (message, type = 'success') => {
        toast.className = `toast toast-${type}`;
        toastMessage.textContent = message;
        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 4000);
    };

    // Custom Console Log
    const logToConsole = (msg, type = 'info') => {
        const consoleEl = document.getElementById('debugConsole');
        if(!consoleEl) return;
        const div = document.createElement('div');
        const time = new Date().toLocaleTimeString();
        let color = '#fff';
        if(type==='error') color = '#ff4444';
        if(type==='success') color = '#00ff00';
        if(type==='warning') color = '#ffaa00';
        
        div.style.color = color;
        div.style.marginBottom = '4px';
        div.style.borderBottom = '1px solid #333';
        div.style.paddingBottom = '4px';
        div.innerHTML = `<span style="color:#888">[${time}]</span> ${msg}`;
        consoleEl.appendChild(div);
        consoleEl.scrollTop = consoleEl.scrollHeight;
    };

    // Wrapper for API calls to catch JSON errors and log them
    const apiCall = async (url, options = {}) => {
        logToConsole(`-> HTTP ${options.method || 'GET'} ${url}`, 'info');
        try {
            const res = await fetch(url, options);
            const text = await res.text();
            
            try {
                const data = JSON.parse(text);
                if(data.debug_log && data.debug_log.trim() !== '') {
                    logToConsole(`PHP OUTPUT: ${data.debug_log}`, 'warning');
                }
                if(data.message) {
                    logToConsole(`API: ${data.message}`, data.success ? 'success' : 'error');
                }
                if(data.trace) {
                    logToConsole(`TRACE: ${data.trace}`, 'error');
                }
                return data;
            } catch(e) {
                logToConsole(`❌ Error parseando JSON. La API devolvió: <br><pre style="white-space:pre-wrap; background:#222; padding:8px;">${text}</pre>`, 'error');
                throw new Error("Invalid JSON response");
            }
        } catch(e) {
            logToConsole(`❌ Error de red o ejecución: ${e.message}`, 'error');
            throw e;
        }
    };

    // Load Dashboard Status
    async function loadStatus() {
        try {
            const result = await apiCall('api.php?action=status');
            const tbody = document.getElementById('requestsTableBody');
            
            if (result && result.data) {
                tbody.innerHTML = '';
                if(result.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="td-placeholder">No hay solicitudes recientes</td></tr>';
                    return;
                }
                
                result.data.forEach(req => {
                    const statusClass = `status-${req.status}`;
                    let translatedStatus = req.status;
                    let detailsHtml = '-';
                    
                    if (req.status === 'accepted') translatedStatus = 'Aceptada (En Proceso)';
                    if (req.status === 'pending') translatedStatus = 'Pendiente SAT';
                    if (req.status === 'finished') translatedStatus = 'Finalizada';
                    if (req.status === 'failed') translatedStatus = 'Fallida';
                    
                    if (req.status === 'finished' && req.status_details) {
                        try {
                            const details = JSON.parse(req.status_details);
                            if (details.stats) {
                                detailsHtml = `<span style="color:var(--success); font-weight:500;">${details.stats.total_xmls} totales, ${details.stats.new_xmls} nuevas</span>`;
                            } else {
                                detailsHtml = `<span style="color:var(--success);">Completado</span>`;
                            }
                        } catch (e) {
                            detailsHtml = `<span style="color:var(--success);">Completado</span>`;
                        }
                    }
                    
                    tbody.innerHTML += `
                        <tr>
                            <td class="mono" style="font-size: 13px;">${req.request_id || '-'}</td>
                            <td><span style="font-weight: 500;">${req.request_type || 'Desconocida'}</span></td>
                            <td>${(req.period_start || '').substring(0,10)} al ${(req.period_end || '').substring(0,10)}</td>
                            <td style="font-weight: bold; color: var(--primary);">${req.xml_count || 0}</td>
                            <td><span class="status-badge ${statusClass}">${translatedStatus}</span></td>
                            <td>${detailsHtml}</td>
                            <td>${req.created_at}</td>
                        </tr>
                    `;
                });
            }
        } catch (e) {}
    }

    // App Logger for debugging console
    window.appLog = function(message) {
        const timestamp = new Date().toLocaleTimeString();
        const logMsg = `[${timestamp}] ${message}`;
        console.log(logMsg);
        
        const debugConsole = document.getElementById('debugConsole');
        if (debugConsole) {
            const div = document.createElement('div');
            div.textContent = `> ${logMsg}`;
            debugConsole.appendChild(div);
            debugConsole.scrollTop = debugConsole.scrollHeight;
        }
    };

    // Auto-tracking: Check pending requests every 2 minutes
    setInterval(async () => {
        const hasPending = document.querySelector('.status-pending, .status-accepted');
        if (hasPending) {
            appLog("Auto-tracking: Verificando pendientes en el SAT...");
            try {
                const data = await apiCall('api.php?action=process_pending', { method: 'POST' }, true);
                if (data && data.success) {
                    const finished = data.details.some(msg => msg.includes('Finalizado'));
                    if (finished) {
                        appLog("Auto-tracking: Solicitud completada. Ejecutando Sincronización FTP automática...");
                        showToast('¡Nuevos XMLs descargados y subidos al FTP!', 'success');
                        
                        // Auto-sync analytics without human intervention
                        try {
                            const syncData = await apiCall('api.php?action=sync_analytics', { method: 'POST' }, true);
                            if (syncData && syncData.success) {
                                appLog("Sincronización FTP completada automáticamente.");
                                loadAnalytics();
                            }
                        } catch (err) {
                            appLog("Error en sincronización FTP automática.");
                        }
                    } else {
                        if (data.details.length > 0) appLog(`Auto-tracking: ${data.details.join(', ')}`);
                    }
                    loadStatus();
                }
            } catch(e) {
                appLog("Auto-tracking falló en esta iteración.");
            }
        }
    }, 120000); // 120,000 ms = 2 minutes

    // Analytics & Charts
    let ftpChartInstance = null;
    let reqChartInstance = null;

    const loadAnalytics = async () => {
        try {
            const data = await apiCall('api.php?action=analytics');
            if (data && data.success) {
                renderCharts(data.ftp, data.requests);
            }
        } catch (e) {
            console.error('Error loading analytics', e);
        }
    };

    const renderCharts = (ftpStats, reqStats) => {
        // FTP Bar Chart
        const ftpCtx = document.getElementById('ftpChart');
        if (ftpCtx) {
            const labels = ftpStats.map(s => s.month);
            const values = ftpStats.map(s => s.xml_count);
            
            if (ftpChartInstance) ftpChartInstance.destroy();
            ftpChartInstance = new Chart(ftpCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'XMLs en FTP',
                        data: values,
                        backgroundColor: '#4f46e5',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // Requests Doughnut Chart
        const reqCtx = document.getElementById('requestsChart');
        if (reqCtx) {
            if (reqChartInstance) reqChartInstance.destroy();
            reqChartInstance = new Chart(reqCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Finalizadas', 'Fallidas', 'Pendientes (SAT)'],
                    datasets: [{
                        data: [
                            reqStats.finished || 0,
                            reqStats.failed || 0,
                            (reqStats.pending || 0) + (reqStats.accepted || 0)
                        ],
                        backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    };

    document.getElementById('btnSyncAnalytics')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnSyncAnalytics');
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Sincronizando (puede tardar)...';
        btn.disabled = true;
        
        try {
            const data = await apiCall('api.php?action=sync_analytics', { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success) {
                    loadAnalytics();
                }
            }
        } catch (e) {
            showToast('Error al sincronizar FTP', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Sincronizar FTP';
            btn.disabled = false;
        }
    });

    document.getElementById('btnVerifySync')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnVerifySync');
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Verificando (puede tardar)...';
        btn.disabled = true;
        appLog("Iniciando Verificación de Integridad FTP vs Local...");
        
        try {
            const data = await apiCall('api.php?action=verify_sync', { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success) {
                    appLog(`Verificación completada. Archivos faltantes subidos: ${data.uploaded}`);
                    if (data.uploaded > 0) {
                        appLog(`Archivos subidos: ${data.details.join(', ')}`);
                        // Refresh analytics to show newly uploaded files
                        loadAnalytics();
                    }
                } else {
                    appLog(`Error en verificación: ${data.message}`);
                }
            }
        } catch (e) {
            appLog("Error al verificar integridad de FTP.");
            showToast('Error al verificar FTP', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Verificar Integridad';
            btn.disabled = false;
        }
    });

    // Helper to toggle form lock
    const toggleFormLock = (formId, editBtnId, saveBtnId, locked) => {
        const form = document.getElementById(formId);
        const editBtn = document.getElementById(editBtnId);
        const saveBtn = document.getElementById(saveBtnId);
        
        Array.from(form.elements).forEach(el => {
            if (el.tagName === 'INPUT') el.disabled = locked;
        });
        
        if (editBtn) editBtn.style.display = locked ? 'inline-flex' : 'none';
        if (saveBtn) saveBtn.style.display = locked ? 'none' : 'inline-flex';
    };

    // Load Config
    const loadConfig = async () => {
        try {
            const data = await apiCall('api.php?action=get_config');
            if (data && data.success) {
                const config = data.data;
                
                // Set FTP
                document.getElementById('ftpHost').value = config.ftp_host || '';
                document.getElementById('ftpUser').value = config.ftp_user || '';
                document.getElementById('ftpPath').value = config.ftp_path || '/';
                if (config.ftp_password_set) document.getElementById('ftpPass').placeholder = "••••••••";
                
                if (config.ftp_host) toggleFormLock('ftpForm', 'btnEditFtp', 'btnSaveFtp', true);
                
                // Set FIEL
                if (config.fiel_password_set) {
                    document.getElementById('pwdStatus').textContent = "Contraseña e.firma guardada";
                    document.getElementById('pwdStatus').className = "status-indicator text-success";
                    document.getElementById('fielPassword').placeholder = "••••••••";
                    toggleFormLock('passwordForm', 'btnEditFielPwd', 'btnSaveFielPwd', true);
                    
                    // We assume certificates are uploaded if password is set to simplify the UI
                    toggleFormLock('fielForm', 'btnEditFielFiles', 'btnSaveFiel', true);
                }
                
                // Set CIEC
                document.getElementById('rfcInput').value = config.rfc || '';
                if (config.ciec_password_set) document.getElementById('ciecInput').placeholder = "••••••••";
                
                if (config.rfc && config.ciec_password_set) {
                    toggleFormLock('ciecForm', 'btnEditCiec', 'btnSaveCiec', true);
                }
            }
        } catch (e) {
            console.error(e);
        }
    };
    
    // Add Edit Button Listeners
    document.getElementById('btnEditFtp')?.addEventListener('click', () => toggleFormLock('ftpForm', 'btnEditFtp', 'btnSaveFtp', false));
    document.getElementById('btnEditFielPwd')?.addEventListener('click', () => toggleFormLock('passwordForm', 'btnEditFielPwd', 'btnSaveFielPwd', false));
    document.getElementById('btnEditFielFiles')?.addEventListener('click', () => toggleFormLock('fielForm', 'btnEditFielFiles', 'btnSaveFiel', false));
    document.getElementById('btnEditCiec')?.addEventListener('click', () => toggleFormLock('ciecForm', 'btnEditCiec', 'btnSaveCiec', false));

    // Form Submissions
    document.getElementById('ftpForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            ftp_host: document.getElementById('ftpHost').value,
            ftp_user: document.getElementById('ftpUser').value,
            ftp_pass: document.getElementById('ftpPass').value,
            ftp_path: document.getElementById('ftpPath').value,
        };
        
        try {
            const data = await apiCall('api.php?action=config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) loadConfig();
            }
        } catch (e) {
            showToast('Error al guardar configuración FTP', 'error');
        }
    });

    document.getElementById('ciecForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            rfc: document.getElementById('rfcInput').value,
            ciec_password: document.getElementById('ciecInput').value
        };
        
        try {
            const data = await apiCall('api.php?action=config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) loadConfig();
            }
        } catch (e) {
            showToast('Error al guardar credenciales CIEC', 'error');
        }
    });

    document.getElementById('passwordForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const pwd = document.getElementById('fielPassword').value;
        if(!pwd) return;
        
        try {
            const data = await apiCall('api.php?action=config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fiel_password: pwd })
            });
            if(data && data.success) {
                document.getElementById('pwdStatus').textContent = "Contraseña e.firma guardada";
                document.getElementById('pwdStatus').className = "status-indicator text-success";
                document.getElementById('fielPassword').value = '';
            }
            if(data) showToast(data.message, data.success ? 'success' : 'error');
        } catch (e) {
            showToast('Error al guardar la contraseña', 'error');
        }
    });

    document.getElementById('fielForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const cer = document.getElementById('cerFile').files[0];
        const key = document.getElementById('keyFile').files[0];
        
        if (!cer && !key) {
            showToast('Selecciona al menos un archivo', 'error');
            return;
        }
        
        const fd = new FormData();
        if(cer) fd.append('cer_file', cer);
        if(key) fd.append('key_file', key);
        
        try {
            const data = await apiCall('api.php?action=upload_fiel', {
                method: 'POST',
                body: fd
            });
            if(data) showToast(data.message, data.success ? 'success' : 'error');
        } catch (e) {
            showToast('Error al subir los archivos de la e.firma', 'error');
        }
    });

    // Test Connections
    document.getElementById('btnTestFtp').addEventListener('click', async () => {
        const btn = document.getElementById('btnTestFtp');
        btn.disabled = true;
        btn.innerHTML = 'Conectando...';
        
        try {
            const data = await apiCall('api.php?action=test_ftp');
            if(data) showToast(data.message, data.success ? 'success' : 'error');
        } catch (e) {
            showToast('Error al probar conexión FTP', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Probar Conexión';
        }
    });

    document.getElementById('btnTestEfirma').addEventListener('click', async () => {
        const btn = document.getElementById('btnTestEfirma');
        btn.disabled = true;
        btn.innerHTML = 'Validando...';
        
        try {
            const data = await apiCall('api.php?action=test_efirma');
            if(data) showToast(data.message, data.success ? 'success' : 'error');
        } catch (e) {
            showToast('Error al probar e.firma', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Probar e.firma';
        }
    });

    document.getElementById('btnTestCiec')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnTestCiec');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Resolviendo...';
        
        try {
            const data = await apiCall('api.php?action=test_ciec');
            if(data) showToast(data.message, data.success ? 'success' : 'error');
        } catch (e) {
            showToast('Error al probar CIEC', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Probar CIEC (Resolver Captcha)';
        }
    });

    // Download Today
    document.getElementById('btnDownloadToday').addEventListener('click', async () => {
        const btn = document.getElementById('btnDownloadToday');
        btn.innerHTML = 'Solicitando...';
        btn.disabled = true;
        appLog("Iniciando Descarga Hoy (e.firma)...");
        
        try {
            const data = await apiCall('api.php?action=download_today', { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success) {
                    appLog(`Solicitud al SAT enviada. ID: ${data.data?.request_id}`);
                    loadStatus();
                } else {
                    appLog(`Fallo en Descarga Hoy: ${data.message}`);
                }
            }
        } catch (e) {
            appLog("Excepción en Descarga Hoy.");
            showToast('Error al solicitar descarga al SAT', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-download"></i> Descargar Hoy (e.firma)';
            btn.disabled = false;
        }
    });

    // Download Instant
    document.getElementById('btnDownloadInstant')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnDownloadInstant');
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Solicitando con IA (puede tardar minutos)...';
        btn.disabled = true;
        appLog("Iniciando Descarga Instantánea (CIEC) - Resolviendo Captcha de fondo...");
        
        try {
            const data = await apiCall('api.php?action=download_ciec_instant', { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success && data.stats) {
                    appLog(`Descarga Instantánea completada. XMLs nuevos subidos: ${data.stats.new_xmls}`);
                    alert(`PROCESO COMPLETADO\nFacturas leídas (este mes): ${data.stats.total_xmls}\nNuevas subidas al FTP: ${data.stats.new_xmls}`);
                    
                    // Auto-sync analytics without human intervention
                    appLog("Ejecutando Sincronización FTP automática...");
                    try {
                        const syncData = await apiCall('api.php?action=sync_analytics', { method: 'POST' }, true);
                        if (syncData && syncData.success) {
                            appLog("Sincronización FTP completada automáticamente.");
                            loadAnalytics();
                        }
                    } catch (err) {}
                    
                    loadStatus();
                } else {
                    appLog(`Fallo en Descarga Instantánea: ${data.message}`);
                }
            }
        } catch (e) {
            appLog("Excepción en Descarga Instantánea.");
            showToast('Error en descarga instantánea', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Descarga Instantánea (CIEC)';
            btn.disabled = false;
        }
    });

    // Process Pending (Manual)
    document.getElementById('btnProcessPending').addEventListener('click', async () => {
        const btn = document.getElementById('btnProcessPending');
        btn.innerHTML = '<i class="fa-solid fa-rotate fa-spin"></i> Verificando...';
        btn.disabled = true;
        appLog("Verificación manual de pendientes iniciada...");
        
        try {
            const data = await apiCall('api.php?action=process_pending', { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success) {
                    const finished = data.details.some(msg => msg.includes('Finalizado'));
                    if (finished) {
                        appLog("Verificación manual: Encontrados XMLs nuevos listos.");
                        showToast('¡Nuevos XMLs descargados y subidos al FTP!', 'success');
                        
                        // Auto-sync analytics
                        appLog("Ejecutando Sincronización FTP automática...");
                        try {
                            const syncData = await apiCall('api.php?action=sync_analytics', { method: 'POST' }, true);
                            if (syncData && syncData.success) {
                                appLog("Sincronización FTP completada automáticamente.");
                                loadAnalytics();
                            }
                        } catch (err) {}
                    } else {
                        appLog(`Verificación manual: ${data.details.join(', ')}`);
                    }
                    loadStatus();
                } else {
                    appLog(`Fallo en verificación manual: ${data.message}`);
                }
            }
        } catch (e) {
            appLog("Excepción en verificación manual.");
            showToast('Error al verificar pendientes', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Verificar Pendientes';
            btn.disabled = false;
        }
    });

    // Clear History
    document.getElementById('btnClearHistory')?.addEventListener('click', async () => {
        if(!confirm('¿Estás seguro de que deseas limpiar el historial de solicitudes?')) return;
        
        const btn = document.getElementById('btnClearHistory');
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Limpiando...';
        btn.disabled = true;
        appLog("Limpiando historial de solicitudes...");
        
        try {
            const data = await apiCall('api.php?action=clear_history', { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success) {
                    appLog("Historial limpiado correctamente.");
                    loadStatus();
                }
            }
        } catch (e) {
            appLog("Error al limpiar el historial.");
            showToast('Error al limpiar el historial', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Limpiar Historial';
            btn.disabled = false;
        }
    });

    // Audit Year
    document.getElementById('btnAuditYear')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnAuditYear');
        const year = document.getElementById('auditYear').value;
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Solicitando...';
        btn.disabled = true;
        appLog(`Iniciando Auditoría Anual (FIEL) para el año ${year}...`);
        
        try {
            const data = await apiCall(`api.php?action=audit_year&year=${year}`, { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success) {
                    appLog(`Auditoría Anual encolada en el SAT. ID: ${data.data?.request_id}`);
                    switchTab('dashboard');
                    loadStatus();
                } else {
                    appLog(`Fallo al encolar Auditoría: ${data.message}`);
                }
            }
        } catch (e) {
            appLog("Excepción en Auditoría Anual.");
            showToast('Error al solicitar auditoría al SAT', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-play"></i> Iniciar Auditoría';
            btn.disabled = false;
        }
    });

    // Download Cancelled
    document.getElementById('btnDownloadCancelled')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnDownloadCancelled');
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Solicitando e interactuando con IA (puede tardar minutos)...';
        btn.disabled = true;
        appLog("Iniciando Auditoría Canceladas (CIEC) - Resolviendo Captcha de fondo...");
        
        try {
            const data = await apiCall('api.php?action=download_cancelled', { method: 'POST' });
            if(data) {
                showToast(data.message, data.success ? 'success' : 'error');
                if(data.success && data.stats) {
                    appLog(`Auditoría Canceladas completada. XMLs nuevos subidos: ${data.stats.new_xmls}`);
                    alert(`PROCESO COMPLETADO\nFacturas canceladas leídas: ${data.stats.total_xmls}\nNuevas subidas al FTP: ${data.stats.new_xmls}`);
                    
                    appLog("Ejecutando Sincronización FTP automática...");
                    try {
                        const syncData = await apiCall('api.php?action=sync_analytics', { method: 'POST' }, true);
                        if (syncData && syncData.success) {
                            appLog("Sincronización FTP completada automáticamente.");
                            loadAnalytics();
                        }
                    } catch (err) {}
                    
                    switchTab('dashboard');
                    loadStatus();
                } else {
                    appLog(`Fallo en Canceladas: ${data.message}`);
                }
            }
        } catch (e) {
            appLog("Excepción en Auditoría Canceladas.");
            showToast('Error al descargar facturas canceladas', 'error');
        } finally {
            btn.innerHTML = '<i class="fa-solid fa-file-invoice-dollar"></i> Buscar Canceladas (Últimos 30 días)';
            btn.disabled = false;
        }
    });

    // Add spin animation CSS dynamically
    const style = document.createElement('style');
    style.innerHTML = `
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        .text-success { color: var(--success); }
    `;
    document.head.appendChild(style);

    // Theme Toggle Logic
    const themeToggleBtn = document.getElementById('themeToggle');
    const themeText = document.getElementById('themeText');
    const icon = themeToggleBtn.querySelector('i');
    
    // Check saved theme or preference
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        document.body.classList.remove('light-theme');
        themeText.textContent = 'Modo Claro';
        icon.className = 'fa-solid fa-sun';
    }
    
    themeToggleBtn.addEventListener('click', () => {
        document.body.classList.toggle('light-theme');
        const isLight = document.body.classList.contains('light-theme');
        localStorage.setItem('theme', isLight ? 'light' : 'dark');
        
        themeText.textContent = isLight ? 'Modo Oscuro' : 'Modo Claro';
        icon.className = isLight ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
    });

    // Initial load
    loadStatus();
    loadAnalytics();
});
