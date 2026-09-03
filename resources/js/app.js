

import Alpine from 'alpinejs';

window.Alpine = Alpine;

window.propertyLocation = ({ states, initialState, initialMunicipality, initialNeighborhood, initialCity, initialPostalCode, municipalitiesUrl, settlementsUrl }) => ({
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
    init() {
        this.$el.closest('form')?.addEventListener('submit', () => this.syncDisabledFields());
        if (this.mode === 'catalog' && this.state) {
            this.loadMunicipalities(this.state, this.municipality);
        }
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
    async searchSettlements() {
        this.settlements = [];
        this.settlementMessage = '';
        if (!this.state || !this.municipality || this.settlementQuery.trim().length < 2) return;
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
