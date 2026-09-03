

import Alpine from 'alpinejs';

window.Alpine = Alpine;

window.propertyLocation = ({ states, initialState, initialMunicipality, initialNeighborhood, initialCity, initialPostalCode, municipalitiesUrl, postalCodeUrl = '/valuador/locations/postal-code', settlementsUrl }) => ({
    states,
    mode: states.includes(initialState) || !initialState ? 'catalog' : 'manual',
    state: initialState || '',
    municipality: initialMunicipality || '',
    neighborhood: initialNeighborhood || '',
    city: initialCity || '',
    postalCode: initialPostalCode || '',
    municipalities: [],
    settlements: [],
    settlementQuery: initialNeighborhood || '',
    loadingMunicipalities: false,
    loadingSettlements: false,
    settlementMessage: '',
    postalCodeMessage: '',
    init() {
        this.$el.closest('form')?.addEventListener('submit', () => this.syncDisabledFields());
        this.addPostalCodeField();
        if (this.mode === 'catalog' && this.state) {
            this.loadMunicipalities(this.state, this.municipality);
        }
    },
    addPostalCodeField() {
        const stateSelect = this.$el.querySelector('#catalog-state');
        if (!stateSelect || this.$el.querySelector('#catalog-postal-code')) return;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = '<label class="block text-sm font-semibold text-[#0d2723]" for="catalog-postal-code">Código postal</label><input id="catalog-postal-code" type="text" inputmode="numeric" maxlength="5" autocomplete="postal-code" placeholder="Escribe 5 dígitos" class="mt-1 w-full rounded border-[#d8ccb8] bg-white px-3 py-2 text-sm focus:border-[#b89752] focus:ring-[#b89752]"><p class="mt-1 text-xs text-amber-700"></p>';
        const input = wrapper.querySelector('input');
        input.value = this.postalCode;
        input.addEventListener('input', (event) => {
            this.postalCode = event.target.value.replace(/\D/g, '').slice(0, 5);
            event.target.value = this.postalCode;
            this.lookupPostalCode();
        });
        stateSelect.closest('div').parentElement.insertBefore(wrapper, stateSelect.closest('div'));
    },
    syncDisabledFields() {
        const manual = this.mode === 'manual';
        this.$el.querySelectorAll('[name="neighborhood"], [name="city"], [name="state"], [name="postal_code"]').forEach((field) => {
            field.disabled = field.closest('[x-show="mode === \'manual\'"]') ? !manual : manual;
        });
    },
    async loadMunicipalities(state, selected = '') {
        this.municipality = selected;
        this.municipalities = [];
        this.settlements = [];
        this.settlementQuery = '';
        this.loadingMunicipalities = Boolean(state);
        if (!state) {
            this.loadingMunicipalities = false;
            return;
        }
        try {
            const response = await fetch(`${municipalitiesUrl}?state=${encodeURIComponent(state)}`);
            this.municipalities = await response.json();
        } finally {
            this.loadingMunicipalities = false;
        }
    },
    async lookupPostalCode() {
        this.postalCodeMessage = '';
        this.settlements = [];
        if (this.postalCode.trim().length !== 5) return;
        try {
            const response = await fetch(`${postalCodeUrl}?postal_code=${encodeURIComponent(this.postalCode.trim())}`);
            const data = await response.json();
            if (!response.ok) {
                this.postalCodeMessage = data.message || 'No encontramos ese código postal.';
                return;
            }
            this.state = data.state || '';
            this.municipalities = data.municipalities || [];
            this.municipality = this.municipalities.length === 1 ? this.municipalities[0] : '';
            this.settlements = data.settlements || [];
            this.city = this.settlements[0]?.city || this.municipality;
            if (this.settlements.length === 1) this.selectSettlement(this.settlements[0]);
        } catch {
            this.postalCodeMessage = 'No fue posible consultar el código postal.';
        }
    },
    async searchSettlements() {
        this.settlements = [];
        this.settlementMessage = '';
        if (!this.state || !this.municipality || this.settlementQuery.trim().length < 3) return;
        this.loadingSettlements = true;
        try {
            const params = new URLSearchParams({ state: this.state, municipality: this.municipality, q: this.settlementQuery.trim() });
            const response = await fetch(`${settlementsUrl}?${params}`);
            const data = await response.json();
            this.settlements = Array.isArray(data) ? data : [];
            if (!this.settlements.length) this.settlementMessage = 'No encontramos coincidencias.';
        } finally {
            this.loadingSettlements = false;
        }
    },
    selectSettlement(settlement) {
        this.neighborhood = settlement.name;
        this.settlementQuery = settlement.name;
        this.postalCode = settlement.postal_code || '';
        this.city = settlement.city || this.municipality;
        this.settlements = [];
    },
    useManual() {
        this.mode = 'manual';
        this.settlements = [];
    },
    useCatalog() {
        this.mode = 'catalog';
        if (this.state) this.loadMunicipalities(this.state, this.municipality);
    },
});

Alpine.start();
