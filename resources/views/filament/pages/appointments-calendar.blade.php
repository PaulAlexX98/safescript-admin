<x-filament::page>
<div
  x-data="appointmentsPage()"
  x-init="init()"
  class="grid grid-cols-1 lg:grid-cols-12 gap-6"
>
  <!-- Left: Calendar -->
  <div class="lg:col-span-6">
    <div class="flex items-center justify-between mb-3">
      <button class="px-3 py-1 rounded-full border" @click="prevMonth()">‹</button>
      <div class="text-lg font-semibold" x-text="headerLabel"></div>
      <button class="px-3 py-1 rounded-full border" @click="nextMonth()">›</button>
    </div>

    <div class="grid grid-cols-7 text-xs text-gray-500 dark:text-gray-400 mb-2">
      <template x-for="d in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']">
        <div class="px-2 py-1" x-text="d"></div>
      </template>
    </div>

    <div class="grid grid-cols-7 gap-2">
      <template x-for="(cell, idx) in cells" :key="idx">
        <div
          class="rounded-xl p-3 border"
          :class="{
            'opacity-40': cell.outside,
            'bg-gray-100 dark:bg-gray-800': !cell.closed && cell.count === 0,
            'bg-emerald-50 dark:bg-emerald-900/30': !cell.closed && cell.count > 0,
            'bg-gray-200 dark:bg-gray-700': cell.closed,
            'ring-2 ring-emerald-500': selectedDate === cell.date
          }"
          @click="select(cell)"
          :aria-disabled="cell.closed"
        >
          <div class="flex items-center justify-between">
            <div class="font-medium" x-text="cell.day"></div>
            <template x-if="cell.count > 0">
              <span class="text-xs rounded-full px-2 py-0.5 bg-emerald-600 text-white" x-text="cell.count"></span>
            </template>
          </div>
          <div class="mt-2 text-xs" x-show="cell.closed">Closed</div>
        </div>
      </template>
    </div>
  </div>

  <!-- Right: List -->
  <div class="lg:col-span-6">
    <div class="flex items-center justify-between mb-3">
      <div class="text-lg font-semibold" x-text="listHeader"></div>
      <div class="text-sm text-gray-500" x-text="items.length + ' appointment' + (items.length===1?'':'s')"></div>
    </div>

    <template x-if="loading">
      <div class="text-sm text-gray-500">Loading…</div>
    </template>

    <div class="space-y-3" x-show="!loading">
      <template x-for="item in items" :key="item.id">
        <div class="rounded-xl border p-4 flex items-center justify-between">
          <div>
            <div class="text-sm text-gray-500" x-text="item.time"></div>
            <div class="font-medium" x-text="item.name || 'Unknown patient'"></div>
            <div class="text-xs text-gray-500" x-text="item.service"></div>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-xs rounded-full px-2 py-0.5 border" x-text="item.status"></span>
            <a
              :href="orderUrl(item)"
              class="px-3 py-1 rounded-full border hover:bg-gray-50"
            >Open order</a>
          </div>
        </div>
      </template>

      <template x-if="items.length === 0 && !loading">
        <div class="text-sm text-gray-500">No appointments for this date.</div>
      </template>
    </div>
  </div>
</div>

<script>
function appointmentsPage() {
  return {
    // state
    now: new Date(),
    monthStart: null,
    selectedDate: null,
    headerLabel: '',
    listHeader: '',
    cells: [],
    counts: {}, // date => {count, closed}
    items: [],
    loading: false,

    init() {
      this.monthStart = new Date(this.now.getFullYear(), this.now.getMonth(), 1);
      this.selectedDate = this.isoDate(this.now);
      this.refreshMonth();
    },

    prevMonth() { this.monthStart = new Date(this.monthStart.getFullYear(), this.monthStart.getMonth() - 1, 1); this.refreshMonth(); },
    nextMonth() { this.monthStart = new Date(this.monthStart.getFullYear(), this.monthStart.getMonth() + 1, 1); this.refreshMonth(); },

    async refreshMonth() {
      const year = this.monthStart.getFullYear();
      const month = String(this.monthStart.getMonth()+1).padStart(2,'0');
      this.headerLabel = this.monthStart.toLocaleString(undefined, { month: 'long', year: 'numeric' });

      const from = this.isoDate(new Date(year, this.monthStart.getMonth(), 1));
      const to   = this.isoDate(new Date(year, this.monthStart.getMonth()+1, 0));
      const res = await fetch(`/admin/api/appointments/stats?from=${from}&to=${to}`, { headers: { 'Accept':'application/json' }});
      const json = await res.json();
      this.counts = {};
      (json.days || []).forEach(d => this.counts[d.date] = { count: d.count, closed: !!d.closed });

      // build calendar cells (Mon-first)
      const first = new Date(year, this.monthStart.getMonth(), 1);
      const last  = new Date(year, this.monthStart.getMonth()+1, 0);
      const firstDow = (first.getDay() + 6) % 7; // 0..6 (Mon=0)
      const totalDays = last.getDate();
      const cells = [];

      // leading blanks
      for (let i=0;i<firstDow;i++) {
        const d = new Date(first); d.setDate(d.getDate() - (firstDow - i));
        cells.push(this.cellFor(d, true));
      }
      // month days
      for (let i=1;i<=totalDays;i++) {
        const d = new Date(year, this.monthStart.getMonth(), i);
        cells.push(this.cellFor(d, false));
      }
      // trailing blanks to complete weeks
      while (cells.length % 7 !== 0) {
        const d = new Date(last); d.setDate(d.getDate() + (cells.length % 7 === 0 ? 0 : 1));
        last.setDate(last.getDate()+1);
        cells.push(this.cellFor(d, true));
      }

      this.cells = cells;
      // keep selection within month
      this.selectByIso(this.selectedDate || this.isoDate(first));
    },

    cellFor(d, outside) {
      const iso = this.isoDate(d);
      const meta = this.counts[iso] || { count: 0, closed: false };
      return { date: iso, day: d.getDate(), outside, count: meta.count, closed: meta.closed };
    },

    select(cell) {
      if (cell.closed) return;
      this.selectByIso(cell.date);
    },

    async selectByIso(iso) {
      this.selectedDate = iso;
      const pretty = new Date(iso+'T00:00:00');
      this.listHeader = pretty.toLocaleDateString(undefined, { weekday:'long', year:'numeric', month:'long', day:'numeric' });
      this.loading = true;
      const res = await fetch(`/admin/api/appointments/day?date=${iso}`, { headers: { 'Accept':'application/json' }});
      const json = await res.json();
      this.items = json.items || [];
      this.loading = false;
    },

    isoDate(d) { return [d.getFullYear(), String(d.getMonth()+1).padStart(2,'0'), String(d.getDate()).padStart(2,'0')].join('-'); },
    orderUrl(item) {
      // If you have a dedicated Order view route, change this href accordingly:
      return item.orderId ? `/admin/orders/${item.orderId}` : '#';
    },
  }
}
</script>
</x-filament::page>