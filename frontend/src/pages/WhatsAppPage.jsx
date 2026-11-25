import { useMemo, useState } from 'react';
import ChatList from '../components/ChatList';
import ChatWindow from '../components/ChatWindow';
import Topbar from '../components/Topbar';
import { mockConversations } from '../data/mockData';

export default function WhatsAppPage() {
  const [conversations, setConversations] = useState(mockConversations);
  const [selectedId, setSelectedId] = useState(mockConversations[0]?.id ?? 1);
  const [notice] = useState('Modo local activo: preparado para integrar WhatsApp Business API en el futuro.');

  const selectedConversation = useMemo(
    () => conversations.find((conversation) => conversation.id === selectedId) || conversations[0],
    [conversations, selectedId],
  );

  const handleSendMessage = (text) => {
    setConversations((current) =>
      current.map((conversation) => {
        if (conversation.id !== selectedId) return conversation;

        return {
          ...conversation,
          lastSeen: 'Ahora',
          messages: [
            ...conversation.messages,
            {
              id: Date.now(),
              sender: 'me',
              text,
              time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
              status: 'Enviado',
            },
          ],
        };
      }),
    );
  };

  return (
    <>
      <Topbar title="Bandeja WhatsApp" subtitle="Atención local de conversaciones y mensajes" />
      <div className="notice">{notice}</div>

      <section className="chat-layout">
        <ChatList conversations={conversations} selectedId={selectedId} onSelect={setSelectedId} />
        <ChatWindow conversation={selectedConversation} onSendMessage={handleSendMessage} />
      </section>
    </>
  );
}
