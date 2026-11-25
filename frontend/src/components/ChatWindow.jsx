import { useState } from 'react';

export default function ChatWindow({ conversation, onSendMessage }) {
  const [text, setText] = useState('');

  if (!conversation) {
    return <div className="panel chat-window empty">Selecciona una conversación</div>;
  }

  const handleSubmit = (event) => {
    event.preventDefault();
    const value = text.trim();
    if (!value) return;
    onSendMessage(value);
    setText('');
  };

  return (
    <div className="chat-window panel">
      <div className="chat-header-bar">
        <div>
          <strong>{conversation.name}</strong>
          <small>{conversation.status}</small>
        </div>
        <span className="chat-badge">WhatsApp</span>
      </div>

      <div className="chat-body">
        {conversation.messages.map((message) => (
          <div key={message.id} className={`msg ${message.sender === 'me' ? 'user' : 'bot'}`}>
            <div className="msg-text">{message.text}</div>
            <div className="msg-meta">
              <span>{message.time}</span>
              <span>{message.status}</span>
            </div>
          </div>
        ))}
      </div>

      <form className="chat-form-box" onSubmit={handleSubmit}>
        <input
          type="text"
          value={text}
          onChange={(event) => setText(event.target.value)}
          placeholder="Escribe un mensaje..."
          autoComplete="off"
        />
        <button type="submit">Enviar</button>
      </form>
    </div>
  );
}
