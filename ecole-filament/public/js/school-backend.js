window.schoolBackend = function (config) {
    return {
        config, school: null, tenant: '', resource: '', rows: [], next: null, after: null, history: [], search: '', loadSerial: 0, sortDirection: 'asc', sortKey: null,
        busy: false, error: '', message: '', editing: false, editingId: null, form: {}, choices: {}, token: '', key: '',
        importPreview: [], importErrors: [], importTotal: 0,
        transitionRow: null, transitionReason: '', transitionStatus: '',
        detailRow: null, references: {}, total: 0, overview: null,
        async init() {
            this.busy = true;
            try {
                this.token = (await this.request('/school-api/csrf')).csrf_token;
                this.school = (await this.request('/school-api/school')).data;
                this.tenant = this.school.id;
            } catch (e) { this.error = e.message; }
            this.busy = false;
            if (this.tenant) {
                if (config.module === 'dashboard') { await this.loadOverview(); return; }
                this.resource = this.available()[0] || '';
                await this.resetResource();
            }
        },
        async request(url, method = 'GET', data = undefined) {
            const response = await fetch(url, {
                method, credentials: 'same-origin',
                headers: {'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':this.token},
                body: data === undefined ? undefined : JSON.stringify(data),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 401 || response.status === 419) throw new Error('Session expirée. Rechargez la page et reconnectez-vous.');
                if (response.status >= 500) throw new Error('Le serveur ne peut pas traiter cette opération. Consultez les journaux du serveur.');
                const error = new Error(body.errors ? Object.values(body.errors).flat().join('\n') : body.message || `Erreur ${response.status}`);
                error.validation = body.errors || null;
                throw error;
            }
            return body;
        },
        base() { return '/school-api/v1/tenants/' + encodeURIComponent(this.tenant); },
        permissions() { return this.school?.permissions || []; },
        permitted(permission) { return this.permissions().includes(permission); },
        readPermission(resource) { return config.catalog[resource] ? config.catalog[resource].permission + '.read' : config.read[resource]; },
        available() { return config.resources.filter(r => this.permitted(this.readPermission(r))); },
        label(resource) { return resource === 'staff' ? (config.module === 'teachers' ? 'Enseignants' : 'Personnel') : config.labels[resource] || resource; },
        sortField() { const preferred=['name','last_name','first_name','title','label','code','number','matricule']; const cols=this.columns(); return this.sortKey && cols.includes(this.sortKey) ? this.sortKey : (preferred.find(field=>cols.includes(field)) || cols[0] || 'id'); },
        sortedRows() { const field=this.sortField(); const direction=this.sortDirection === 'desc' ? -1 : 1; return [...this.rows].sort((a,b)=>String(this.display(a[field],field) ?? '').localeCompare(String(this.display(b[field],field) ?? ''),'fr',{numeric:true,sensitivity:'base'})*direction); },
        sortBy(field) { if (this.sortKey === field) this.sortDirection=this.sortDirection === 'asc' ? 'desc' : 'asc'; else { this.sortKey=field; this.sortDirection='asc'; } this.rows=this.sortedRows(); this.decorateHeaders(); },
        decorateHeaders() { this.$nextTick(() => document.querySelectorAll('.backend-table thead th:not(.backend-actions-heading)').forEach((th,index) => { const field=this.columns()[index]; if (!field) return; th.dataset.sortField=field; th.setAttribute('aria-sort',this.sortField()===field ? (this.sortDirection==='asc'?'ascending':'descending') : 'none'); const button=th.querySelector('button') || document.createElement('button'); button.type='button'; button.className='backend-column-sort'; button.style.cssText='border:0;background:transparent;color:inherit;font:inherit;font-weight:700;padding:0;cursor:pointer;text-align:left'; button.textContent=this.fieldLabel(field)+' '+(this.sortField()===field ? (this.sortDirection==='asc'?'↑':'↓') : '↕'); button.setAttribute('aria-label','Trier par '+this.fieldLabel(field)); button.onclick=()=>this.sortBy(field); th.replaceChildren(button); })); },
        fieldLabel(key) { return window.schoolPresentation.labels[key] || key.replace(/_/g,' '); },
        valueLabel(value) { return window.schoolPresentation.values[value] || value; },
        createLabel() { return this.resource === 'staff' && config.module === 'teachers' ? 'Nouvel enseignant' : window.schoolPresentation.create[this.resource] || 'Ajouter / enregistrer'; },
        async chooseResource(resource) { if (this.busy) return; this.resource=resource; await this.resetResource(); },
        async loadOverview() {
            this.busy=true; this.error='';
            try { this.overview=(await this.request(this.base()+'/dashboard/overview')).data; }
            catch(e) { this.error=e.message; } finally { this.busy=false; }
        },
        metric(key) { const value=this.overview?.counts?.[key]; return value === undefined ? '—' : key.endsWith('_minor') ? this.display(value,key) : Number(value).toLocaleString('fr-DZ'); },
        hasMetric(key) { return this.overview && Object.hasOwn(this.overview.counts,key); },
        cycleWidth(cycle) { const max=Math.max(1,...(this.overview?.cycles || []).map(c=>Number(c.students))); return Number(cycle.students)/max*100+'%'; },
        today() { return new Date().toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'Africa/Algiers'}); },
        closeModal() { if(this.busy) return; this.editing=false;this.editingId=null;this.transitionRow=null;this.detailRow=null; },
        operation() {
            return config.operations[this.resource] || (config.catalog[this.resource] ? {
                permission: config.catalog[this.resource].permission + '.write', path:this.resource, rules:config.catalog[this.resource].rules,
            } : null);
        },
        canWrite() { return !!this.tenant && !!this.operation() && this.permitted(this.operation().permission); },
        canManageRow() { return config.mutable.includes(this.resource) && this.canWrite(); },
        async resetResource() {
            this.rows = []; this.history = []; this.after = null; this.next = null;
            this.total=0; this.detailRow=null; this.references={};
            this.search = ''; this.sortDirection = 'asc'; this.sortKey = null;
            this.editing = false; this.editingId = null; this.transitionRow = null; this.form = {}; this.choices = {}; this.message = ''; this.error = '';
            if (this.resource) await this.load();
        },
        async load() {
            const serial = ++this.loadSerial;
            this.busy = true; this.error = '';
            try {
                const params = new URLSearchParams({limit:'25'});
                if (this.after) params.set('after', this.after);
                if (this.search.trim()) params.set('q', this.search.trim());
                if (this.resource === 'staff' && ['teachers','staff'].includes(config.module)) params.set('is_teacher',config.module === 'teachers' ? '1' : '0');
                const result = await this.request(this.base() + '/' + this.resource + '?' + params);
                const references={};
                await this.hydrate(result.data, Object.keys(result.data[0] || {}), references);
                if (serial === this.loadSerial) { this.rows = result.data; this.next = result.next; this.total=result.total ?? result.data.length; this.references=references; this.rows=this.sortedRows(); this.decorateHeaders(); }
            } catch (e) { if (serial === this.loadSerial) { this.rows = []; this.next = null; this.error = e.message; } }
            finally { if (serial === this.loadSerial) this.busy = false; }
        },
        async searchChanged() { this.after = null; this.history = []; await this.load(); },
        async clearSearch() { this.search = ''; await this.searchChanged(); },
        async nextPage() { this.history.push(this.after); this.after = this.next; await this.load(); },
        async previous() { this.after = this.history.pop(); await this.load(); },
        downloadStudentTemplate() {
            const csv = '\uFEFFmatricule;first_name;last_name;birth_date;phone;email;active\r\nEX-001;Amine;Benali;2015-04-12;0550000000;amine@example.test;true\r\n';
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'}));
            link.download = 'modele-eleves.csv'; link.click(); URL.revokeObjectURL(link.href);
        },
        parseCsv(text) {
            const delimiter = (text.split(/\r?\n/,1)[0].match(/;/g) || []).length >= (text.split(/\r?\n/,1)[0].match(/,/g) || []).length ? ';' : ',';
            const records = []; let row = [], cell = '', quoted = false;
            for (let i=0; i<text.length; i++) {
                const char = text[i];
                if (quoted) {
                    if (char === '"' && text[i+1] === '"') { cell += '"'; i++; }
                    else if (char === '"') quoted = false;
                    else cell += char;
                } else if (char === '"' && cell === '') quoted = true;
                else if (char === delimiter) { row.push(cell); cell = ''; }
                else if (char === '\n' || char === '\r') {
                    if (char === '\r' && text[i+1] === '\n') i++;
                    row.push(cell); cell = '';
                    if (row.some(value => value.trim() !== '')) records.push(row);
                    row = [];
                } else cell += char;
            }
            if (quoted) throw new Error('Le fichier CSV contient un guillemet non fermé.');
            row.push(cell); if (row.some(value => value.trim() !== '')) records.push(row);
            return records;
        },
        async readStudentCsv(event) {
            this.clearStudentCsv(); this.error = ''; this.message = '';
            const file = event.target.files?.[0]; event.target.value = '';
            if (!file) return;
            if (!file.name.toLowerCase().endsWith('.csv') || file.size > 2*1024*1024) {
                this.importErrors = ['Choisissez un fichier CSV de 2 Mo maximum.']; return;
            }
            try {
                const records = this.parseCsv((await file.text()).replace(/^\uFEFF/, ''));
                if (records.length < 2) throw new Error('Le fichier doit contenir une ligne d’en-têtes et au moins un élève.');
                const normalize = value => value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]/g,'');
                const aliases = {
                    matricule:'matricule', idmatricule:'matricule', first_name:'first_name', firstname:'first_name', prenom:'first_name',
                    last_name:'last_name', lastname:'last_name', nom:'last_name', birth_date:'birth_date', birthdate:'birth_date', datenaissance:'birth_date',
                    phone:'phone', telephone:'phone', email:'email', courriel:'email', active:'active', actif:'active',
                };
                const headers = records[0].map(header => aliases[normalize(header)] || aliases[normalize(header).replace(/_/g,'')] || '');
                for (const required of ['matricule','first_name','last_name']) {
                    if (!headers.includes(required)) throw new Error('Colonne obligatoire absente : ' + required + '. Utilisez le modèle CSV fourni.');
                }
                if (headers.some(header => !header) || new Set(headers).size !== headers.length) throw new Error('En-tête inconnu ou colonne répétée. Utilisez le modèle CSV fourni.');
                this.importTotal = records.length - 1;
                if (this.importTotal > 500) throw new Error('Limite : 500 élèves par import.');
                this.importPreview = records.slice(1).map((values,index) => {
                    if (values.length !== headers.length) { this.importErrors.push('Ligne '+(index+2)+' : nombre de colonnes incorrect.'); return null; }
                    const row = Object.fromEntries(headers.map((header,i) => [header,values[i].trim()]));
                    if (row.active !== undefined) {
                        const active = normalize(row.active);
                        if (['true','1','oui','yes','actif'].includes(active)) row.active = true;
                        else if (['false','0','non','no','inactif'].includes(active)) row.active = false;
                        else this.importErrors.push('Ligne '+(index+2)+' : active doit valoir true/false, oui/non ou 1/0.');
                    }
                    for (const key of ['birth_date','phone','email']) if (row[key] === '') delete row[key];
                    return row;
                }).filter(Boolean);
                if (!this.importPreview.length && !this.importErrors.length) this.importErrors.push('Aucune ligne à importer.');
            } catch (error) { this.importPreview = []; this.importErrors = [error.message]; }
        },
        async saveStudentCsv() {
            if (this.busy || !this.canWrite() || !this.importPreview.length || this.importErrors.length) return;
            this.busy = true; this.error = ''; this.message = '';
            try {
                const result = await this.request(this.base()+'/students/import','POST',{rows:this.importPreview});
                this.message = result.data.created+' élève(s) importé(s).'; this.clearStudentCsv(); this.after=null; this.history=[];
                await this.load();
            } catch (error) {
                this.importErrors = error.validation ? Object.entries(error.validation).map(([key, messages]) => key.replace(/^rows\.(\d+)/,(_,n)=>'Ligne '+(Number(n)+2)).replace(/\.([^.]+)$/,' · $1')+' : '+messages.join(', ')) : [error.message];
            } finally { this.busy = false; }
        },
        clearStudentCsv() { this.importPreview=[]; this.importErrors=[]; this.importTotal=0; },
        columns() { const row=this.rows[0] || {}; return (window.schoolPresentation.columns[this.resource] || Object.keys(row).filter(k=>!['id','tenant_id','idempotency_key','user_id','created_at'].includes(k))).filter(key=>Object.hasOwn(row,key)); },
        detailFields() { return Object.keys(this.detailRow || {}).filter(key=>!['id','tenant_id','idempotency_key'].includes(key)); },
        initials(row) { return (row.name || row.last_name || row.title || row.label || '').split(/\s+/).slice(0,2).map(s=>[...s][0]).join(''); },
        isBadge(column) { return ['active','is_teacher','status','kind','basis','can_collect'].includes(column); },
        badgeClass(value) { return [false,'cancelled','absent','suspended'].includes(value) ? 'pink' : ['pending','late','missing','planned'].includes(value) ? 'amber' : 'primary'; },
        recordLabel(row,resource) {
            if(!row) return '—';
            if(resource === 'enrollments') return this.relationLabel('students',row.student_id)+' · '+this.relationLabel('cohorts',row.cohort_id);
            if(resource === 'invoices') return this.relationLabel('enrollments',row.enrollment_id)+' · '+this.display(row.amount_minor,'amount_minor');
            if(resource === 'sessions') return this.relationLabel('cohorts',row.cohort_id)+' · '+this.relationLabel('subjects',row.subject_id)+' · '+this.display(row.starts_at,'starts_at');
            if(resource === 'contracts') return this.relationLabel('staff',row.staff_id)+' · '+this.valueLabel(row.basis);
            return row.name || row.title || [row.last_name,row.first_name].filter(Boolean).join(' ') || row.label || row.number || this.label(resource);
        },
        relationLabel(resource,id) { if(!id) return '—'; const row=this.references[resource]?.[id]; return row ? this.recordLabel(row,resource) : this.permitted(this.readPermission(resource)) ? 'Référence indisponible' : 'Accès restreint'; },
        async hydrate(rows,fields,cache,depth=0) {
            if(depth>3) return;
            const dependencies={enrollments:['student_id','cohort_id'],invoices:['enrollment_id'],sessions:['cohort_id','subject_id'],contracts:['staff_id']};
            const relations={};
            for(const field of fields) {
                const relation=config.relations[field];
                if(!relation || !this.permitted(this.readPermission(relation))) continue;
                relations[relation] ||= new Set();
                for(const row of rows) if(row[field] && !cache[relation]?.[row[field]]) relations[relation].add(row[field]);
            }
            await Promise.all(Object.entries(relations).map(async ([relation,ids])=>{
                const list=[...ids]; const fetched=[];
                for(let i=0;i<list.length;i+=100) {
                    const params=new URLSearchParams({limit:'100'}); list.slice(i,i+100).forEach(id=>params.append('ids[]',id));
                    const response=await this.request(this.base()+'/'+relation+'?'+params);
                    cache[relation] ||= {};
                    for(const row of response.data) {cache[relation][row.id]=row;fetched.push(row);}
                }
                if(dependencies[relation]) await this.hydrate(fetched,dependencies[relation],cache,depth+1);
            }));
        },
        display(value, column) {
            if (value === null || value === undefined) return '—';
            if(column === 'user_id') return value ? 'Compte lié' : 'Aucun compte';
            if(config.relations[column]) return this.relationLabel(config.relations[column],value);
            if (column.endsWith('_minor')) return (Number(value)/100).toLocaleString('fr-DZ', {style:'currency',currency:'DZD'});
            if(typeof value === 'boolean') return column === 'active' ? (value ? 'Actif' : 'Inactif') : (value ? 'Oui' : 'Non');
            if((column.endsWith('_on') || column === 'birth_date' || column === 'month') && /^\d{4}-\d{2}-\d{2}$/.test(value)) return new Date(value+'T12:00:00').toLocaleDateString('fr-FR');
            if(column.endsWith('_at') && !Number.isNaN(Date.parse(value))) return new Date(value).toLocaleString('fr-FR',{timeZone:'Africa/Algiers',dateStyle:'short',timeStyle:'short'});
            if(column === 'changed_fields' && Array.isArray(value)) return value.map(key=>this.fieldLabel(key)).join(', ');
            if(['status','kind','basis','method','action'].includes(column) && window.schoolPresentation.values[value]) return this.valueLabel(value);
            if(column === 'entity') return this.label(value);
            if(/^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(String(value))) return 'Référence '+String(value).slice(0,8);
            if (typeof value === 'object') return JSON.stringify(value);
            return String(value);
        },
        fields() {
            return Object.entries(this.operation()?.rules || {}).map(([key, rule]) => {
                const parts = rule.split('|');
                const enumeration = parts.find(r => r.startsWith('in:'));
                const boolean = parts.includes('boolean');
                const numeric = parts.includes('integer') || parts.includes('numeric');
                const money=key.endsWith('_minor');
                const dateTime=key.endsWith('_at') && rule.includes('date_format:');
                return {key, required:parts.includes('required'),
                    label:this.fieldLabel(key)+(money ? ' (DA)' : ''),
                    type:dateTime ? 'datetime-local' : parts.includes('date_format:Y-m-d') ? 'date' : numeric ? 'number' : parts.includes('email') ? 'email' : 'text',
                    multiline:['description','body','reason','address'].includes(key),
                    step:money ? '0.01' : parts.includes('integer') ? '1' : 'any',
                    min:numeric ? ((parts.find(r=>r.startsWith('between:'))?.split(':')[1].split(',')[0] ?? parts.find(r=>r.startsWith('min:'))?.slice(4) ?? 0)/(money ? 100 : 1)) : undefined,
                    max:numeric && parts.some(r=>r.startsWith('between:')) ? Number(parts.find(r=>r.startsWith('between:')).split(',')[1])/(money ? 100 : 1) : undefined,
                    options:enumeration ? enumeration.slice(3).split(',').map(v=>({value:v,label:this.valueLabel(v)})) : boolean ? [{value:'true',label:'Oui'},{value:'false',label:'Non'}] : this.choices[key],
                };
            });
        },
        async openForm(row = null) {
            if (!this.canWrite()) return;
            this.busy = true; this.error = ''; this.message = ''; this.transitionRow = null; this.choices = {};
            this.detailRow=null;
            this.form = {}; this.key = crypto.randomUUID(); this.editingId = row && this.canManageRow() ? row.id : null;
            for (const [key, rule] of Object.entries(this.operation().rules)) this.form[key] = row?.[key] ?? (rule.includes('boolean') ? 'true' : '');
            if(this.resource==='staff' && !row) this.form.is_teacher=config.module==='teachers' ? 'true' : 'false';
            for(const field of this.fields()) {
                if(row && field.key.endsWith('_minor') && row[field.key] != null) this.form[field.key]=Number(row[field.key])/100;
                if(row && field.type==='datetime-local' && row[field.key]) this.form[field.key]=new Date(row[field.key]).toLocaleString('sv-SE',{timeZone:'Africa/Algiers'}).replace(' ','T').slice(0,16);
            }
            try {
                for (const field of this.fields()) {
                    const relation = config.relations[field.key];
                    if (!relation || !this.permitted(this.readPermission(relation))) continue;
                    let cursor = null; const options = []; const items=[];
                    do {
                        const result = await this.request(this.base() + '/' + relation + '?limit=100' + (cursor ? '&after=' + cursor : ''));
                        for (const item of result.data) {
                            if (field.key === 'teacher_id' && !item.is_teacher) continue;
                            items.push(item);
                        }
                        cursor = result.next;
                    } while (cursor);
                    await this.hydrate(items,Object.keys(items[0] || {}),this.references);
                    for(const item of items) options.push({value:item.id,label:this.recordLabel(item,relation)+(item.matricule ? ' · '+item.matricule : '')});
                    this.choices[field.key] = options;
                }
                this.editing = true;
            } catch (e) { this.error = e.message; }
            finally { this.busy = false; }
        },
        async save() {
            if (this.busy || !this.canWrite()) return;
            this.busy = true; this.error = ''; this.message = '';
            try {
                const data = {};
                for (const [key, rule] of Object.entries(this.operation().rules)) {
                    const value = this.form[key];
                    if (value === '' || value === null || value === undefined) { if (rule.includes('nullable')) data[key] = null; continue; }
                    data[key] = rule.includes('boolean') ? value === true || value === 'true' : rule.includes('integer') || rule.includes('numeric') ? Number(value) : value;
                    if(key.endsWith('_minor')) data[key]=Math.round(Number(value)*100);
                    if(key.endsWith('_at') && rule.includes('date_format:')) data[key]=value.length === 16 ? value+':00+01:00' : value+'+01:00';
                }
                if (['enrollments','payments','refunds','disbursements'].includes(this.resource)) data.idempotency_key = this.key;
                const updating = !!this.editingId;
                await this.request(this.base() + '/' + this.operation().path + (updating ? '/' + encodeURIComponent(this.editingId) : ''),
                    updating ? 'PUT' : this.operation().method || 'POST', data);
                this.editing = false; this.editingId = null; this.message = updating ? 'Modification enregistrée.' : 'Enregistrement enregistré.';
                this.after = null; this.history = [];
                await this.load();
            } catch (e) { this.error = e.message; }
            finally { this.busy = false; }
        },
        async deleteRow(row) {
            if (this.busy || !this.canManageRow()) return;
            if (!window.confirm('Supprimer cet enregistrement ? Cette action est irréversible et sera refusée si des données liées en dépendent.')) return;
            this.busy = true; this.error = ''; this.message = '';
            try {
                await this.request(this.base() + '/' + this.resource + '/' + encodeURIComponent(row.id), 'DELETE');
                this.message = 'Enregistrement supprimé.';
                await this.load();
            } catch (e) { this.error = e.message; }
            finally { this.busy = false; }
        },
        canTransition() {
            const permission = {enrollments:'enrollments.write',sessions:'pedagogy.write',assessments:'pedagogy.write',work_logs:'payroll.write',lesson_versions:'pedagogy.write',announcements:'pedagogy.write'}[this.resource];
            return !!permission && this.permitted(permission);
        },
        transitionStatuses() { return this.resource === 'enrollments' ? ['validated','suspended','cancelled'] : this.resource === 'sessions' ? ['published','cancelled'] : []; },
        openTransition(row) { this.editing = false; this.transitionRow = row; this.transitionReason = ''; this.transitionStatus = this.transitionStatuses()[0] || ''; },
        async saveTransition() {
            if (this.busy || !this.canTransition()) return;
            this.busy = true; this.error = '';
            try {
                await this.request(this.base() + '/' + this.resource + '/' + this.transitionRow.id + '/transition', 'POST', {reason:this.transitionReason,status:this.transitionStatus});
                this.transitionRow = null; this.message = 'État enregistré.'; await this.load();
            } catch (e) { this.error = e.message; }
            finally { this.busy = false; }
        },
    };
};
