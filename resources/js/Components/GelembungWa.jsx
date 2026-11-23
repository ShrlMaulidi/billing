/** Teks WhatsApp: *tebal* & _miring_ seperti di aplikasi WhatsApp (teks di-escape React, hanya penanda yang diubah). */
function TeksWa({ teks }) {
    return teks.split(/(\*[^*\n]+\*|_[^_\n]+_)/g).map((bagian, i) => {
        if (/^\*[^*\n]+\*$/.test(bagian)) return <strong key={i}>{bagian.slice(1, -1)}</strong>;
        if (/^_[^_\n]+_$/.test(bagian)) return <em key={i}>{bagian.slice(1, -1)}</em>;
        return <span key={i}>{bagian}</span>;
    });
}

/** Pratinjau pesan bergaya gelembung chat WhatsApp. */
export default function GelembungWa({ teks }) {
    return <div className="wa-preview"><div className="bubble"><TeksWa teks={teks} /></div></div>;
}
