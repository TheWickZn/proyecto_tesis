export default function Topbar({ title, subtitle }) {
  return (
    <header className="page-header">
      <div>
        <p className="eyebrow">Sistema</p>
        <h1>{title}</h1>
      </div>
      {subtitle ? <p className="page-subtitle">{subtitle}</p> : null}
    </header>
  );
}
