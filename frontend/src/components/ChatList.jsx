export default function ChatList({ conversations, selectedId, onSelect }) {
  return (
    <aside className="chat-sidebar">
      <div className="chat-search">
        <input type="text" placeholder="Buscar conversación" />
      </div>

      {conversations.map((conversation) => (
        <button
          key={conversation.id}
          type="button"
          className={`conversation-item${selectedId === conversation.id ? ' selected' : ''}`}
          onClick={() => onSelect(conversation.id)}
        >
          <div className="conversation-avatar">{conversation.name.charAt(0)}</div>

          <div className="conversation-body">
            <div className="conversation-header">
              <strong>{conversation.name}</strong>
              <span>{conversation.lastSeen}</span>
            </div>
            <div className="conversation-meta">
              <small>{conversation.status}</small>
              {conversation.unread ? <span className="unread-pill">{conversation.unread}</span> : null}
            </div>
          </div>
        </button>
      ))}
    </aside>
  );
}
