import { useState } from 'react';
import { Edit2, Plus, Trash2 } from 'react-feather';
import { Button, Card, CardBody } from 'reactstrap';
import { dummy } from '../lib/ui';
import DataTable from './DataTable';
import FilterBar from './FilterBar';
import PageHeader from './PageHeader';
import { ActionButton, ActionGroup, ConfirmDialog, FormModal } from './ui';

/**
 * Pola Kelola Data: List → Form Tambah/Edit (modal) → Hapus (konfirmasi).
 * Simpan/hapus dummy kecuali diberi onSave(values, row) / onDelete(row).
 */
export default function CrudPage({
    title, crumbs = ['Kelola Data'], entity, rows, columns, fields, filters, filterFields = ['cabang'], defaults = {},
    rowActions, headerActions, modalSize, tableProps = {}, canAdd = true, canEdit = true, canDelete = true, deleteLabel, before, after, formExtra,
    onSave, onDelete,
}) {
    const [form, setForm] = useState(null); // { row } | null
    const [del, setDel] = useState(null);
    const name = entity ?? title;

    const actions = (row) => (
        <ActionGroup>
            {rowActions?.(row, { edit: () => setForm({ row }) })}
            {canEdit && <ActionButton icon={Edit2} label="Edit" color="warning" onClick={() => setForm({ row })} />}
            {canDelete && <ActionButton icon={Trash2} label="Hapus" color="danger" onClick={() => setDel(row)} />}
        </ActionGroup>
    );

    return (
        <>
            <PageHeader title={title} crumbs={crumbs} />
            <div className="container-fluid">
                {before}
                <Card>
                    <CardBody>
                        <DataTable
                            columns={columns}
                            data={rows}
                            actions={canEdit || canDelete || rowActions ? actions : undefined}
                            toolbar={
                                <>
                                    {canAdd && <Button color="primary" onClick={() => setForm({ row: null })}><Plus size={14} className="me-1" />Tambah {name}</Button>}
                                    {headerActions}
                                    {filterFields?.length > 0 && <FilterBar filters={filters} fields={filterFields} />}
                                </>
                            }
                            {...tableProps}
                        />
                    </CardBody>
                </Card>
                {after}
            </div>

            {fields && (
                <FormModal
                    isOpen={!!form}
                    toggle={() => setForm(null)}
                    title={`${form?.row ? 'Edit' : 'Tambah'} ${name}`}
                    fields={fields}
                    initial={form?.row ?? defaults}
                    size={modalSize}
                    onSubmit={(values) => (onSave ? onSave(values, form?.row) : dummy(`${form?.row ? 'Perubahan' : 'Penambahan'} ${name}`))}
                >
                    {formExtra}
                </FormModal>
            )}
            <ConfirmDialog
                isOpen={!!del}
                toggle={() => setDel(null)}
                title={`Hapus ${name}`}
                message={<>Yakin ingin menghapus <strong>{del && (deleteLabel ? deleteLabel(del) : del.nama ?? del.id)}</strong>? Tindakan ini akan tercatat di Logs.</>}
                confirmLabel="Hapus"
                onConfirm={() => (onDelete ? onDelete(del) : dummy(`Penghapusan ${name}`))}
            />
        </>
    );
}
