<script>
    import Authenticated from '$layouts/Authenticated.svelte'
    import Button from '$lib/components/ui/Button.svelte'
    import { router, useForm } from '@inertiajs/svelte'
    import { showToast } from '$lib/toast.js'
    import UploadSimple from 'phosphor-svelte/lib/UploadSimple'

    let { receipts = { data: [], links: [] }, stats = {}, search = '', filterStatus = '', statusOptions = [] } = $props()
    let searchValue = $state(search)
    let statusValue = $state(filterStatus)
    const importForm = useForm({ format: 'golden_radio', file: null })

    function money(cents) {
        return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format((cents || 0) / 100)
    }
    function date(value) { return value ? new Intl.DateTimeFormat('it-IT').format(new Date(`${value}T00:00:00`)) : '—' }
    function statusLabel(value) { return statusOptions.find((x) => x.value === value)?.label ?? value }
    function applyFilters() {
        const params = new URLSearchParams()
        if (searchValue) params.set('search', searchValue)
        if (statusValue) params.set('status', statusValue)
        window.location.href = `/receipts${params.size ? `?${params}` : ''}`
    }
    function upload() {
        if (!importForm.file) return showToast('Seleziona un CSV.', 'error')
        importForm.post('/receipts/import', { forceFormData: true, preserveScroll: true })
    }
    function transmit(receipt) {
        if (!window.confirm('Trasmettere questo documento fiscale a OpenAPI/Agenzia delle Entrate? Operazione non reversibile.')) return
        router.post(`/receipts/${receipt.id}/submit`, {}, { preserveScroll: true })
    }
    function sync(receipt) { router.post(`/receipts/${receipt.id}/sync`, {}, { preserveScroll: true }) }
</script>

<Authenticated>
    {#snippet headerActions()}<a href="/receipts/create" class="btn-brand text-sm">Nuovo corrispettivo</a>{/snippet}

    <div class="page-shell w-full space-y-5 pb-8">
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <div class="card-brand p-4"><p class="text-xs text-brand-secondary">Documenti</p><p class="mt-1 text-xl font-semibold text-brand-deep">{stats.count ?? 0}</p></div>
            <div class="card-brand p-4"><p class="text-xs text-brand-secondary">Operazioni</p><p class="mt-1 text-xl font-semibold text-brand-deep">{stats.transactions ?? 0}</p></div>
            <div class="card-brand p-4"><p class="text-xs text-brand-secondary">Imponibile</p><p class="mt-1 text-xl font-semibold text-brand-deep">{money(stats.net)}</p></div>
            <div class="card-brand p-4"><p class="text-xs text-brand-secondary">IVA</p><p class="mt-1 text-xl font-semibold text-brand-deep">{money(stats.vat)}</p></div>
            <div class="card-brand p-4 col-span-2 lg:col-span-1"><p class="text-xs text-brand-secondary">Lordo</p><p class="mt-1 text-xl font-semibold text-brand-deep">{money(stats.gross)}</p></div>
        </div>

        <div class="card-brand p-4 sm:p-5">
            <div class="flex flex-col lg:flex-row gap-3 lg:items-end">
                <label class="flex-1"><span class="text-xs font-medium text-brand-secondary">Cerca</span><input bind:value={searchValue} onkeydown={(e) => e.key === 'Enter' && applyFilters()} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" placeholder="Descrizione o riferimento" /></label>
                <label class="w-full lg:w-48"><span class="text-xs font-medium text-brand-secondary">Stato</span><select bind:value={statusValue} class="mt-1 w-full rounded-lg border border-brand-secondary/20 bg-white px-3 py-2 text-sm"><option value="">Tutti</option>{#each statusOptions as option}<option value={option.value}>{option.label}</option>{/each}</select></label>
                <Button variant="outline" onclick={applyFilters}>Filtra</Button>
            </div>
        </div>

        <div class="card-brand p-4 sm:p-5">
            <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                <div class="flex-1"><h2 class="font-semibold text-brand-deep">Importa riepilogo Golden Radio</h2><p class="text-xs text-brand-secondary mt-1">CSV prodotto da golden_radio_corrispettivi.py. Duplicati mensili saltati.</p></div>
                <input type="file" accept=".csv,text/csv" onchange={(e) => importForm.file = e.currentTarget.files?.[0] ?? null} class="text-sm" />
                <Button onclick={upload} disabled={importForm.processing}><UploadSimple class="size-4" /> {importForm.processing ? 'Importazione...' : 'Importa'}</Button>
            </div>
            {#if importForm.errors.file}<p class="mt-2 text-sm text-error">{importForm.errors.file}</p>{/if}
        </div>

        <div class="card-brand overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-muted text-left text-xs uppercase tracking-wide text-brand-secondary"><tr><th class="px-4 py-3">Data</th><th class="px-4 py-3">Descrizione</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3 text-right">Operazioni</th><th class="px-4 py-3 text-right">Imponibile</th><th class="px-4 py-3 text-right">IVA</th><th class="px-4 py-3 text-right">Lordo</th><th class="px-4 py-3">Stato</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody class="divide-y divide-border-light">
                        {#each receipts.data ?? [] as receipt}
                            <tr class="hover:bg-surface-muted/50">
                                <td class="px-4 py-3 whitespace-nowrap">{date(receipt.date)}</td>
                                <td class="px-4 py-3"><p class="font-medium text-brand-deep">{receipt.description}</p><p class="text-xs text-brand-secondary">{receipt.external_reference ?? receipt.provider_document_number ?? ''}</p></td>
                                <td class="px-4 py-3">{receipt.kind === 'monthly_summary' ? 'Mensile' : 'Singolo'}</td>
                                <td class="px-4 py-3 text-right">{receipt.transaction_count}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{money(receipt.total_net)}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{money(receipt.total_vat)}</td>
                                <td class="px-4 py-3 text-right font-medium whitespace-nowrap">{money(receipt.total_gross)}</td>
                                <td class="px-4 py-3"><span class="badge-neutral">{statusLabel(receipt.status)}</span>{#if receipt.error_message}<p class="mt-1 max-w-48 text-xs text-error">{receipt.error_message}</p>{/if}</td>
                                <td class="px-4 py-3"><div class="flex justify-end gap-2"><a class="btn-outline text-xs" href={`/receipts/${receipt.id}/edit`}>Apri</a>{#if receipt.provider_id}<button class="btn-outline text-xs" onclick={() => sync(receipt)}>Aggiorna stato</button>{:else if receipt.kind === 'individual' && receipt.transmission_channel !== 'register_only'}<button class="btn-brand text-xs" onclick={() => transmit(receipt)}>Trasmetti</button>{/if}</div></td>
                            </tr>
                        {:else}
                            <tr><td colspan="9" class="px-4 py-10 text-center text-brand-secondary">Nessun corrispettivo registrato.</td></tr>
                        {/each}
                    </tbody>
                </table>
            </div>
            {#if (receipts.links ?? []).length > 3}<div class="flex flex-wrap gap-1 border-t border-border-light p-3">{#each receipts.links as link}<a href={link.url ?? '#'} class="rounded-lg px-3 py-1.5 text-sm {link.active ? 'bg-primary text-white' : 'text-brand-secondary hover:bg-surface-muted'}" aria-disabled={!link.url}>{@html link.label}</a>{/each}</div>{/if}
        </div>
    </div>
</Authenticated>
