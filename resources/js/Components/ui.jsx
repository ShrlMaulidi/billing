import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, Inbox, UploadCloud } from 'react-feather';
import { Button, FormFeedback, Spinner, FormGroup, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import { label as toLabel } from '../lib/format';
import Select from './Select';

const STATUS_COLOR = {
    aktif: 'success', tidak_aktif: 'danger', nonaktif: 'danger', on: 'success', off: 'warning', berhenti: 'dark',
    lunas: 'success', belum_lunas: 'danger', sebagian: 'warning', pending: 'warning', menunggu_verifikasi: 'warning',
    disetujui: 'success', diterima: 'success', dibayar: 'primary', ditolak: 'danger', online: 'success', offline: 'danger',
    connected: 'success', disconnected: 'danger', true: 'success', false: 'secondary',
};
const STATUS_TEXT = { true: 'Ya', false: 'Tidak', connected: 'Terhubung', disconnected: 'Terputus', on: 'On', off: 'Off' };

export function StatusBadge({ value, text }) {
    const key = String(value);
    return <span className={`badge badge-light-${STATUS_COLOR[key] ?? 'info'}`}>{text ?? STATUS_TEXT[key] ?? toLabel(key)}</span>;
}

export function StatCard({ title, value, sub, icon: IconCmp, color = 'primary', onClick, onMouseEnter }) {
    return (
        <div className={`card stat-card small-widget mb-4 ${onClick ? 'clickable' : ''}`} onClick={onClick} onMouseEnter={onMouseEnter}>
            <div className="card-body d-flex align-items-center gap-3">
                <div className={`stat-icon bg-light-${color} font-${color}`}>{IconCmp && <IconCmp size={22} />}</div>
                <div className="flex-grow-1 min-w-0">
                    <span className="f-light">{title}</span>
                    <h4 className="mb-0 mt-1 text-truncate">{value}</h4>
                    {sub && <small className="f-light">{sub}</small>}
                </div>
            </div>
        </div>
    );
}

// Avatar inisial (pengganti foto sampai fitur upload foto tersedia)
export function Avatar({ name = '', size = 37 }) {
    const initials = name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();
    return (
        <span className="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style={{ width: size, height: size, fontSize: size * 0.4, fontWeight: 600 }}>
            {initials}
        </span>
    );
}

/**
 * Tombol aksi baris tabel: ikon seragam 32px + tooltip.
 * color: primary (lihat/edit) | danger (hapus/tolak) | success (setujui) | warning | info | dark
 */
export function ActionButton({ icon: IconCmp, label, color = 'primary', onClick, href, disabled, loading }) {
    const props = { className: `btn-action btn-action-${color}`, 'data-tip': label, 'aria-label': label };
    const content = loading ? <Spinner size="sm" /> : <IconCmp />;
    if (href) return <Link href={href} prefetch {...props}>{content}</Link>;
    return <button type="button" {...props} onClick={onClick} disabled={disabled || loading}>{content}</button>;
}

export const ActionGroup = ({ children }) => <div className="action-group">{children}</div>;

export function EmptyState({ text = 'Belum ada data', children }) {
    return (
        <div className="text-center py-5 f-light">
            <Inbox size={40} className="mb-2" />
            <p className="mb-2">{text}</p>
            {children}
        </div>
    );
}

export function ErrorState({ text = 'Gagal memuat data.', onRetry }) {
    return (
        <div className="text-center py-5">
            <AlertTriangle size={40} className="font-danger mb-2" />
            <p className="mb-2">{text}</p>
            {onRetry && <Button color="primary" outline size="sm" onClick={onRetry}>Coba lagi</Button>}
        </div>
    );
}

export function SkeletonRows({ cols = 5, rows = 6 }) {
    return Array.from({ length: rows }, (_, r) => (
        <tr key={r}>{Array.from({ length: cols }, (_, c) => <td key={c}><span className="skeleton" style={{ width: `${50 + ((r * 7 + c * 13) % 45)}%` }} /></td>)}</tr>
    ));
}

export function CurrencyInput({ value, onChange, invalid, ...rest }) {
    const shown = value === '' || value === null || value === undefined ? '' : new Intl.NumberFormat('id-ID').format(value);
    return (
        <div className="input-group">
            <span className="input-group-text">Rp</span>
            <Input {...rest} invalid={invalid} inputMode="numeric" value={shown} onChange={(e) => {
                const digits = e.target.value.replace(/\D/g, '');
                onChange(digits === '' ? '' : Number(digits));
            }} />
        </div>
    );
}

export function FileUpload({ value, onChange, accept = '.pdf,.jpg,.jpeg,.png', hint = 'PDF / JPG / PNG, maks 2 MB' }) {
    const ref = useRef();
    const name = value instanceof File ? value.name : value;
    return (
        <div className="file-drop" onClick={() => ref.current.click()}>
            <UploadCloud className="font-primary mb-1" />
            <div>{name ? <strong>{name}</strong> : 'Klik untuk memilih file'}</div>
            <small className="f-light">{hint}</small>
            <input ref={ref} type="file" hidden accept={accept} onChange={(e) => onChange(e.target.files[0] ?? null)} />
        </div>
    );
}

export function ConfirmDialog({ isOpen, toggle, title = 'Konfirmasi', message, confirmLabel = 'Ya, lanjutkan', color = 'danger', onConfirm }) {
    return (
        <Modal isOpen={isOpen} toggle={toggle} centered>
            <ModalHeader toggle={toggle}>{title}</ModalHeader>
            <ModalBody>{message}</ModalBody>
            <ModalFooter>
                <Button color="light" onClick={toggle}>Batal</Button>
                <Button color={color} onClick={() => { onConfirm?.(); toggle(); }}>{confirmLabel}</Button>
            </ModalFooter>
        </Modal>
    );
}

const optionList = (options = []) => options.map((o) => (typeof o === 'object' ? o : { value: o, label: toLabel(o) }));

export function Field({ field, value, onChange, error, values }) {
    const { name, type = 'text', label, options, placeholder, help, required, render, disabled } = field;
    const common = { id: name, name, placeholder, disabled, invalid: !!error };
    let control;
    switch (type) {
        case 'currency':
            control = <CurrencyInput {...common} value={value ?? ''} onChange={onChange} />;
            break;
        case 'select':
            control = (
                <Select id={name} value={value} onChange={onChange} invalid={!!error} disabled={disabled}
                    options={optionList(typeof options === 'function' ? options(values) : options)} emptyLabel="— Pilih —" />
            );
            break;
        case 'switch':
            return (
                <FormGroup switch className="mb-3">
                    <Input type="switch" id={name} checked={!!value} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
                    <Label check for={name}>{label}</Label>
                    {help && <div className="form-text">{help}</div>}
                </FormGroup>
            );
        case 'radio':
            control = (
                <div className="btn-group w-100" role="group">
                    {optionList(options).map((o) => (
                        <Button key={o.value} type="button" outline={String(value) !== String(o.value)} color="primary" onClick={() => onChange(o.value)} disabled={disabled}>{o.label}</Button>
                    ))}
                </div>
            );
            break;
        case 'file':
            control = <FileUpload value={value} onChange={onChange} accept={field.accept} />;
            break;
        case 'static':
            control = <div className="form-control-plaintext">{render ? render(values) : value}</div>;
            break;
        case 'custom':
            // Kontrol buatan halaman sendiri, mis. dropdown yang memuat pilihan dari server
            control = render({ value, onChange, values, invalid: !!error });
            break;
        case 'textarea':
            control = <Input type="textarea" rows={field.rows ?? 3} {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
            break;
        default:
            control = <Input type={type} {...common} value={value ?? ''} autoComplete={type === 'password' ? 'new-password' : undefined} onChange={(e) => onChange(e.target.value)} />;
    }
    return (
        <FormGroup className="mb-3">
            {label && <Label for={name} className="form-label">{label}{required && <span className="text-danger"> *</span>}</Label>}
            {control}
            {error && <FormFeedback className="d-block">{error}</FormFeedback>}
            {help && <div className="form-text">{help}</div>}
        </FormGroup>
    );
}

// Modal form berbasis skema field (onSubmit menerima values).
// field.resets: daftar field yang dikosongkan saat field ini berubah (mis. ganti cabang → kosongkan router).
export function FormModal({ isOpen, toggle, title, fields, initial = {}, onSubmit, size = 'lg', submitLabel = 'Simpan', children }) {
    const [values, setValues] = useState(initial);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (isOpen) { setValues(initial); setErrors({}); }
    }, [isOpen]); // eslint-disable-line react-hooks/exhaustive-deps

    const visible = fields.filter((f) => !f.show || f.show(values));
    const submit = async (e) => {
        e.preventDefault();
        const errs = {};
        visible.forEach((f) => {
            const v = values[f.name];
            if (f.required && (v === '' || v === null || v === undefined)) errs[f.name] = 'Wajib diisi';
        });
        setErrors(errs);
        if (Object.keys(errs).length) return;
        // onSubmit boleh async: modal tetap terbuka sampai selesai, error validasi server ditampilkan per field
        const result = onSubmit?.(values);
        if (result?.then) {
            setSaving(true);
            try {
                await result;
            } catch (err) {
                setErrors(Object.fromEntries(Object.entries(err?.errors ?? {}).map(([k, v]) => [k, [].concat(v)[0]])));
                return;
            } finally {
                setSaving(false);
            }
        }
        toggle();
    };

    return (
        <Modal isOpen={isOpen} toggle={toggle} size={size} centered scrollable>
            <form onSubmit={submit} noValidate className="d-contents">
                <ModalHeader toggle={toggle}>{title}</ModalHeader>
                <ModalBody>
                    <div className="row">
                        {visible.map((f) => (
                            <div key={f.name} className={f.col ?? (fields.length > 4 ? 'col-md-6' : 'col-12')}>
                                <Field field={f} value={values[f.name]} values={values} error={errors[f.name]} onChange={(v) => setValues((s) => ({ ...s, ...Object.fromEntries((f.resets ?? []).map((k) => [k, ''])), [f.name]: v }))} />
                            </div>
                        ))}
                    </div>
                    {children?.(values)}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" type="button" onClick={toggle}>Batal</Button>
                    <Button color="primary" type="submit" disabled={saving}>{saving && <Spinner size="sm" className="me-1" />}{submitLabel}</Button>
                </ModalFooter>
            </form>
        </Modal>
    );
}
