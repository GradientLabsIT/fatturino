<script>
    import Button from '$lib/components/ui/Button.svelte'
    import FormField from '$lib/components/ui/FormField.svelte'
    import Input from '$lib/components/ui/Input.svelte'
    import Select from '$lib/components/ui/Select.svelte'
    import { useForm } from '@inertiajs/svelte'
    import { showToast } from '$lib/toast.js'
    import Plus from 'phosphor-svelte/lib/Plus'
    import Trash from 'phosphor-svelte/lib/Trash'

    let { receipt = null, initial = {}, formData = {} } = $props()
    const editing = !!receipt
    const readOnly = editing && !receipt.is_editable && !!receipt.provider_id

    function blankLine() {
        return {
            item_type: 'service', description: '', quantity: '1.00', unit_price_gross: '0.00',
            vat_rate_code: '22', vat_nature: '', country_group: '', net_amount: '0.00',
            vat_amount: '0.00', gross_amount: '0.00',
        }
    }

    const form = useForm({
        date: initial.date ?? new Date().toISOString().slice(0, 10),
        kind: initial.kind ?? 'monthly_summary',
        status: initial.status ?? 'draft',
        transmission_channel: initial.transmission_channel ?? 'register_only',
        description: initial.description ?? '',
        period_start: initial.period_start ?? '',
        period_end: initial.period_end ?? '',
        transaction_count: initial.transaction_count ?? 1,
        external_reference: initial.external_reference ?? '',
        cash_payment_amount: initial.cash_payment_amount ?? '0.00',
        electronic_payment_amount: initial.electronic_payment_amount ?? '0.00',
        uncollected_amount: initial.uncollected_amount ?? '0.00',
        lines: initial.lines?.length ? initial.lines : [blankLine()],
    })

    const totals = $derived(form.lines.reduce((sum, line) => ({
        net: sum.net + Number(line.net_amount || 0),
        vat: sum.vat + Number(line.vat_amount || 0),
        gross: sum.gross + Number(line.gross_amount || 0),
    }), { net: 0, vat: 0, gross: 0 }))

    function money(value) {
        return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(value || 0)
    }

    function setKind(event) {
        form.kind = event.target.value
        if (form.kind === 'monthly_summary') form.transmission_channel = 'register_only'
    }

    function addLine() {
        form.lines = [...form.lines, blankLine()]
    }

    function removeLine(index) {
        if (form.lines.length > 1) form.lines = form.lines.filter((_, i) => i !== index)
    }

    function submit() {
        const options = {
            preserveScroll: true,
            onSuccess: () => showToast(editing ? 'Corrispettivo aggiornato.' : 'Corrispettivo creato.'),
        }
        if (editing) form.put(`/receipts/${receipt.id}`, options)
        else form.post('/receipts', options)
    }

</script>

<div class="page-shell pb-24 sm:pb-8 w-full space-y-5">
    <div class="flex justify-end gap-2">
        <a href="/receipts" class="btn-outline text-sm">Indietro</a>
        {#if !readOnly}
            <Button class="btn-brand text-sm" onclick={submit} disabled={form.processing}>
                {form.processing ? 'Salvataggio...' : (editing ? 'Aggiorna corrispettivo' : 'Crea corrispettivo')}
            </Button>
        {/if}
    </div>
    {#if readOnly}
        <div class="rounded-xl border border-info/30 bg-info/5 px-4 py-3 text-sm text-brand-deep">
            Documento trasmesso: dati fiscali in sola lettura. Usa “Aggiorna stato” dalla lista.
        </div>
    {/if}
    {#if form.errors.receipt}
        <div class="rounded-xl border border-error/30 bg-error/5 px-4 py-3 text-sm text-error">{form.errors.receipt}</div>
    {/if}

    <fieldset disabled={readOnly} class="space-y-5 disabled:opacity-70">
        <div class="card-brand p-4 sm:p-6">
            <h2 class="text-base font-semibold text-brand-deep mb-4">Dati registro</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <FormField label="Data" required error={form.errors.date}>
                    <Input type="date" bind:value={form.date} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                </FormField>
                <FormField label="Tipo" required error={form.errors.kind}>
                    <Select useNative value={form.kind} onchange={setKind} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm bg-white">
                        {#each formData.kinds ?? [] as option}<option value={option.value}>{option.label}</option>{/each}
                    </Select>
                </FormField>
                <FormField label="Stato" required error={form.errors.status}>
                    <Select useNative bind:value={form.status} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm bg-white">
                        {#each formData.statuses ?? [] as option}<option value={option.value}>{option.label}</option>{/each}
                    </Select>
                </FormField>
                <FormField label="Operazioni" required error={form.errors.transaction_count}>
                    <Input type="number" min="1" bind:value={form.transaction_count} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                </FormField>
                <FormField class="sm:col-span-2" label="Descrizione" required error={form.errors.description}>
                    <Input bind:value={form.description} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" placeholder="Golden Radio - corrispettivi 2026-08" />
                </FormField>
                <FormField class="sm:col-span-2" label="Riferimento esterno" error={form.errors.external_reference}>
                    <Input bind:value={form.external_reference} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" placeholder="stripe:2026-08" />
                </FormField>
                <FormField label="Periodo dal" error={form.errors.period_start}>
                    <Input type="date" bind:value={form.period_start} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                </FormField>
                <FormField label="Periodo al" error={form.errors.period_end}>
                    <Input type="date" bind:value={form.period_end} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                </FormField>
                <FormField class="sm:col-span-2" label="Modalità" required error={form.errors.transmission_channel}>
                    <Select useNative bind:value={form.transmission_channel} disabled={form.kind === 'monthly_summary'} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm bg-white">
                        {#each formData.channels ?? [] as option}<option value={option.value}>{option.label}</option>{/each}
                    </Select>
                    {#if form.kind === 'monthly_summary'}<p class="mt-1 text-xs text-brand-secondary">Riepiloghi mensili restano nel registro; non diventano singoli scontrini.</p>{/if}
                </FormField>
            </div>
        </div>

        <div class="card-brand p-4 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-semibold text-brand-deep">Righe IVA</h2>
                <Button type="button" variant="outline" class="text-sm" onclick={addLine}><Plus class="size-4" /> Aggiungi riga</Button>
            </div>
            <div class="space-y-4">
                {#each form.lines as line, index}
                    <div class="rounded-xl border border-border-light bg-surface-muted/40 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-semibold uppercase tracking-wide text-brand-secondary">Riga {index + 1}</span>
                            <button type="button" class="text-error disabled:opacity-30" onclick={() => removeLine(index)} disabled={form.lines.length === 1} aria-label="Rimuovi riga"><Trash class="size-4" /></button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                            <FormField class="sm:col-span-2 lg:col-span-3" label="Descrizione" required error={form.errors[`lines.${index}.description`]}>
                                <Input bind:value={line.description} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                            </FormField>
                            <FormField label="Tipo">
                                <Select useNative bind:value={line.item_type} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm bg-white"><option value="service">Servizio</option><option value="goods">Bene</option></Select>
                            </FormField>
                            <FormField label="Quantità" required error={form.errors[`lines.${index}.quantity`]}>
                                <Input type="number" min="0.01" step="0.01" bind:value={line.quantity} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                            </FormField>
                            <FormField label="Aliquota/Natura" required error={form.errors[`lines.${index}.vat_rate_code`]}>
                                <Select useNative bind:value={line.vat_rate_code} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm bg-white">{#each formData.vatRates ?? [] as rate}<option value={rate}>{rate}</option>{/each}</Select>
                            </FormField>
                            <FormField label="Imponibile" required error={form.errors[`lines.${index}.net_amount`]}>
                                <Input type="number" step="0.01" bind:value={line.net_amount} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                            </FormField>
                            <FormField label="IVA" required error={form.errors[`lines.${index}.vat_amount`]}>
                                <Input type="number" step="0.01" bind:value={line.vat_amount} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                            </FormField>
                            <FormField label="Lordo" required error={form.errors[`lines.${index}.gross_amount`]}>
                                <Input type="number" step="0.01" bind:value={line.gross_amount} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                            </FormField>
                            <FormField label="Prezzo unitario lordo" required error={form.errors[`lines.${index}.unit_price_gross`]}>
                                <Input type="number" step="0.01" bind:value={line.unit_price_gross} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" />
                            </FormField>
                            <FormField label="Gruppo paese">
                                <Input bind:value={line.country_group} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" placeholder="IT+UE" />
                            </FormField>
                            <FormField class="sm:col-span-2" label="Norma IVA">
                                <Input bind:value={line.vat_nature} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" placeholder="Fuori campo art. 7-octies" />
                            </FormField>
                        </div>
                    </div>
                {/each}
            </div>
            <div class="mt-4 grid grid-cols-3 gap-3 rounded-xl bg-brand-deep px-4 py-3 text-white">
                <div><p class="text-xs text-white/60">Imponibile</p><p class="font-semibold">{money(totals.net)}</p></div>
                <div><p class="text-xs text-white/60">IVA</p><p class="font-semibold">{money(totals.vat)}</p></div>
                <div><p class="text-xs text-white/60">Lordo</p><p class="font-semibold">{money(totals.gross)}</p></div>
            </div>
        </div>

        <div class="card-brand p-4 sm:p-6">
            <h2 class="text-base font-semibold text-brand-deep mb-4">Incassi</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <FormField label="Contanti" required error={form.errors.cash_payment_amount}><Input type="number" min="0" step="0.01" bind:value={form.cash_payment_amount} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" /></FormField>
                <FormField label="Elettronico" required error={form.errors.electronic_payment_amount}><Input type="number" min="0" step="0.01" bind:value={form.electronic_payment_amount} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" /></FormField>
                <FormField label="Non riscosso" required error={form.errors.uncollected_amount}><Input type="number" min="0" step="0.01" bind:value={form.uncollected_amount} class="mt-1 w-full rounded-lg border border-brand-secondary/20 px-3 py-2 text-sm" /></FormField>
            </div>
        </div>
    </fieldset>
</div>
