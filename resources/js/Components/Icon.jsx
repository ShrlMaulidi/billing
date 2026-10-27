// Ikon sidebar dari sprite Cuba (stroke & fill)
export default function Icon({ id }) {
    return (
        <>
            <svg className="stroke-icon"><use href={`/cuba/svg/sprite.svg#stroke-${id}`} /></svg>
            <svg className="fill-icon"><use href={`/cuba/svg/sprite.svg#fill-${id}`} /></svg>
        </>
    );
}
