'use client';

import { useState, useEffect, useRef } from 'react';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000/api/v1';

export default function ChatBoxApp() {
  // Auth State
  const [authToken, setAuthToken] = useState(null);
  const [currentUser, setCurrentUser] = useState(null);
  const [currentProperty, setCurrentProperty] = useState(null);
  const [allProperties, setAllProperties] = useState([]);
  
  // Login Form
  const [loginEmail, setLoginEmail] = useState('ambitionmanagements@gmail.com');
  const [loginPassword, setLoginPassword] = useState('password123');
  const [authError, setAuthError] = useState('');
  const [isLoggingIn, setIsLoggingIn] = useState(false);
  const [showForgotModal, setShowForgotModal] = useState(false);
  const [forgotEmail, setForgotEmail] = useState('');
  const [forgotPassword, setForgotPassword] = useState('');
  const [forgotMsg, setForgotMsg] = useState({ type: '', text: '' });

  // Navigation View
  // 'inbox' | 'leads' | 'platform' | 'customizer' | 'team' | 'analytics'
  const [activeView, setActiveView] = useState('inbox');

  // Live Inbox State
  const [conversations, setConversations] = useState([]);
  const [activeConv, setActiveConv] = useState(null);
  const [filter, setFilter] = useState('unassigned');
  const [searchQuery, setSearchQuery] = useState('');
  const [replyText, setReplyText] = useState('');
  const [cannedResponses, setCannedResponses] = useState([]);
  const [showCannedMenu, setShowCannedMenu] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);

  // CRM Leads State
  const [leads, setLeads] = useState([]);
  const [leadSearch, setLeadSearch] = useState('');
  const [leadStatusFilter, setLeadStatusFilter] = useState('all');
  const [selectedLead, setSelectedLead] = useState(null);
  const [editLeadNotes, setEditLeadNotes] = useState('');
  const [editLeadStatus, setEditLeadStatus] = useState('new');
  const [showEditLeadModal, setShowEditLeadModal] = useState(false);

  // Company FAQs & Knowledge Base State
  const [faqs, setFaqs] = useState([]);
  const [faqSearch, setFaqSearch] = useState('');
  const [showFaqModal, setShowFaqModal] = useState(false);
  const [newFaq, setNewFaq] = useState({ question: '', answer: '', category: 'General' });
  const [showFaqQuickPicker, setShowFaqQuickPicker] = useState(false);

  // Super Admin Platform State
  const [platformStats, setPlatformStats] = useState({ tenants: 0, properties: 0, agents: 0, messages: 0 });
  const [platformTenants, setPlatformTenants] = useState([]);
  const [platformUsers, setPlatformUsers] = useState([]);
  const [showNewTenantModal, setShowNewTenantModal] = useState(false);
  const [newCompany, setNewCompany] = useState({ name: '', adminName: '', adminEmail: '', adminPass: '', siteName: '', siteUrl: '', plan: 'pro' });
  const [editingUser, setEditingUser] = useState(null);
  const [showEditUserModal, setShowEditUserModal] = useState(false);

  // Embed Guide Modal
  const [showEmbedModal, setShowEmbedModal] = useState(false);
  const [copiedSnippet, setCopiedSnippet] = useState(false);

  // Realtime Alert Toast
  const [alertToast, setAlertToast] = useState(null);

  // Audio Ping
  const playAudioChime = () => {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
      osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.12); // A5
      gain.gain.setValueAtTime(0.3, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.45);
    } catch (e) {
      // Audio autoplay policy
    }
  };

  // Check cached token on mount
  useEffect(() => {
    const cached = localStorage.getItem('chatbox_token');
    if (cached) {
      setAuthToken(cached);
      fetchUserProfile(cached);
    }
  }, []);

  // Fetch Current User
  const fetchUserProfile = async (token) => {
    try {
      const res = await fetch(`${API_BASE}/auth/me`, {
        headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
      });
      if (res.status === 401) {
        handleLogout();
        return;
      }
      const json = await res.json();
      if (json.success) {
        setCurrentUser(json.data.user);
        const props = json.data.properties || [];
        setAllProperties(props);
        if (props.length > 0) {
          setCurrentProperty(props[0]);
        }
        if (json.data.user.role === 'super_admin') {
          setActiveView('platform');
          loadPlatformData(token);
        } else {
          setActiveView('inbox');
        }
      }
    } catch (e) {
      console.error(e);
    }
  };

  // Polling loop for active session
  useEffect(() => {
    if (!authToken || !currentUser) return;

    loadConversations();
    loadCannedResponses();
    if (activeView === 'leads') loadLeads();
    if (activeView === 'faqs' || faqs.length === 0) loadFaqs();

    const interval = setInterval(() => {
      loadConversations(true);
      if (activeView === 'leads') loadLeads();
      if (activeView === 'faqs') loadFaqs();
      if (currentUser?.role === 'super_admin' && activeView === 'platform') loadPlatformData(authToken);
    }, 4000);

    return () => clearInterval(interval);
  }, [authToken, currentUser, activeView, filter, currentProperty]);

  // Login Submit
  const handleLogin = async (email, password) => {
    setIsLoggingIn(true);
    setAuthError('');
    try {
      const res = await fetch(`${API_BASE}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ email, password })
      });
      const json = await res.json();
      if (json.success && json.data?.token) {
        setAuthToken(json.data.token);
        localStorage.setItem('chatbox_token', json.data.token);
        setCurrentUser(json.data.user);
        const props = json.data.properties || [];
        setAllProperties(props);
        if (props.length > 0) setCurrentProperty(props[0]);
        
        if (json.data.user.role === 'super_admin') {
          setActiveView('platform');
          loadPlatformData(json.data.token);
        } else {
          setActiveView('inbox');
        }
      } else {
        setAuthError(json.message || 'Login failed. Please check credentials.');
      }
    } catch (e) {
      setAuthError('Failed to connect to backend at ' + API_BASE + '. Please ensure php artisan serve is running.');
    } finally {
      setIsLoggingIn(false);
    }
  };

  const handleLogout = () => {
    if (authToken) {
      fetch(`${API_BASE}/auth/logout`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      }).catch(() => {});
    }
    setAuthToken(null);
    setCurrentUser(null);
    localStorage.removeItem('chatbox_token');
  };

  // Reset Password (Forgot Password)
  const handleForgotPassword = async (e) => {
    e.preventDefault();
    setForgotMsg({ type: '', text: '' });
    try {
      const res = await fetch(`${API_BASE}/auth/forgot-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ email: forgotEmail, new_password: forgotPassword })
      });
      const json = await res.json();
      if (json.success) {
        setForgotMsg({ type: 'success', text: json.message });
        setTimeout(() => {
          setShowForgotModal(false);
          setLoginEmail(forgotEmail);
          setLoginPassword(forgotPassword);
        }, 1500);
      } else {
        setForgotMsg({ type: 'error', text: json.message || 'Password reset failed.' });
      }
    } catch (e) {
      setForgotMsg({ type: 'error', text: 'Server connection error.' });
    }
  };

  // Load Conversations
  const prevConvCountRef = useRef(0);
  const loadConversations = async (isBackground = false) => {
    if (!authToken) return;
    try {
      const res = await fetch(`${API_BASE}/chat/conversations?filter=${filter}`, {
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      });
      const json = await res.json();
      if (json.success) {
        const list = json.data || [];
        setConversations(list);

        // Calculate unread
        const unread = list.reduce((sum, c) => sum + (c.unread_count || 0), 0);
        setUnreadCount(unread);

        // Detect new incoming conversation & trigger chime / toast
        if (list.length > prevConvCountRef.current && isBackground) {
          const newest = list[0];
          playAudioChime();
          setAlertToast({
            title: `🔔 New Chat: ${newest.visitor?.name || 'Visitor'}`,
            message: newest.latest_message?.content || 'Started a new conversation',
            time: 'Just now'
          });
          setTimeout(() => setAlertToast(null), 5000);
        }
        prevConvCountRef.current = list.length;

        // Keep active conversation refreshed
        if (activeConv) {
          const current = list.find(c => c.id === activeConv.id);
          if (current) setActiveConv(current);
        }
      }
    } catch (e) {
      console.error(e);
    }
  };

  // Load Canned Responses
  const loadCannedResponses = async () => {
    if (!authToken) return;
    try {
      const res = await fetch(`${API_BASE}/chat/canned-responses`, {
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      });
      const json = await res.json();
      if (json.success) setCannedResponses(json.data || []);
    } catch (e) {}
  };

  // Select Conversation
  const selectConversation = async (conv) => {
    setActiveConv(conv);
    // Mark as read
    try {
      await fetch(`${API_BASE}/chat/conversations/${conv.id}/read`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      });
      loadConversations();
    } catch (e) {}
  };

  // Send Message
  const handleSendMessage = async () => {
    if (!replyText.trim() || !activeConv) return;
    const text = replyText.trim();
    setReplyText('');

    try {
      const res = await fetch(`${API_BASE}/chat/conversations/${activeConv.id}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify({ message: text })
      });
      const json = await res.json();
      if (json.success) {
        loadConversations();
      }
    } catch (e) {
      alert('Failed to send message.');
    }
  };

  // Assign to Me
  const handleAssignToMe = async () => {
    if (!activeConv || !currentUser) return;
    try {
      await fetch(`${API_BASE}/chat/conversations/${activeConv.id}/assign`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify({ agent_id: currentUser.id })
      });
      loadConversations();
    } catch (e) {}
  };

  // Close Conversation
  const handleCloseConversation = async () => {
    if (!activeConv) return;
    try {
      await fetch(`${API_BASE}/chat/conversations/${activeConv.id}/status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify({ status: 'closed' })
      });
      loadConversations();
    } catch (e) {}
  };

  // CRM Leads Loader
  const loadLeads = async () => {
    if (!authToken) return;
    try {
      let url = `${API_BASE}/crm/leads`;
      if (leadStatusFilter !== 'all') url += `?status=${leadStatusFilter}`;
      if (leadSearch) url += `${leadStatusFilter !== 'all' ? '&' : '?'}search=${encodeURIComponent(leadSearch)}`;
      const res = await fetch(url, {
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      });
      const json = await res.json();
      if (json.success) {
        setLeads(json.data || []);
      }
    } catch (e) {
      console.error(e);
    }
  };

  // Update Lead Status & Notes
  const handleUpdateLead = async () => {
    if (!selectedLead) return;
    try {
      const res = await fetch(`${API_BASE}/crm/leads/${selectedLead.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          lead_status: editLeadStatus,
          lead_notes: editLeadNotes
        })
      });
      const json = await res.json();
      if (json.success) {
        setShowEditLeadModal(false);
        loadLeads();
      }
    } catch (e) {
      alert('Failed to update lead.');
    }
  };

  // Export Leads to CSV
  const exportLeadsCsv = () => {
    if (leads.length === 0) return alert('No leads to export.');
    const headers = ['Name', 'Email', 'Phone', 'City', 'Status', 'Inquiry', 'Website', 'Created At'];
    const rows = leads.map(l => [
      `"${l.name || ''}"`,
      `"${l.email || ''}"`,
      `"${l.phone || ''}"`,
      `"${l.city || ''}"`,
      `"${l.lead_status || 'new'}"`,
      `"${(l.initial_inquiry || '').replace(/"/g, '""')}"`,
      `"${l.property_name || ''}"`,
      `"${l.created_at || ''}"`
    ]);
    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `ChatBox_Leads_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // --------------------------------------------------------------------------
  // COMPANY FAQS & KNOWLEDGE BASE
  // --------------------------------------------------------------------------
  const loadFaqs = async () => {
    if (!authToken || !currentProperty) return;
    try {
      const res = await fetch(`${API_BASE}/properties/${currentProperty.id}/faqs`, {
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      });
      const json = await res.json();
      if (json.success) {
        setFaqs(json.data || []);
      }
    } catch (e) {
      console.error(e);
    }
  };

  const handleCreateFaq = async (e) => {
    e.preventDefault();
    if (!currentProperty || !authToken) return;
    try {
      const res = await fetch(`${API_BASE}/properties/${currentProperty.id}/faqs`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify(newFaq)
      });
      const json = await res.json();
      if (json.success) {
        setShowFaqModal(false);
        setNewFaq({ question: '', answer: '', category: 'General' });
        loadFaqs();
      } else {
        alert(json.message || 'Failed to create FAQ');
      }
    } catch (e) {
      alert('Error creating FAQ');
    }
  };

  const handleDeleteFaq = async (id) => {
    if (!confirm('Are you sure you want to delete this FAQ?')) return;
    try {
      const res = await fetch(`${API_BASE}/faqs/${id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      });
      const json = await res.json();
      if (json.success) {
        loadFaqs();
      }
    } catch (e) {
      alert('Error deleting FAQ');
    }
  };

  // Toggle Block Visitor
  const handleToggleBlockVisitor = async (visitorId) => {
    if (!visitorId || !authToken) return;
    try {
      const res = await fetch(`${API_BASE}/chat/visitors/${visitorId}/block`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${authToken}`, 'Accept': 'application/json' }
      });
      const json = await res.json();
      if (json.success) {
        alert(json.message);
        loadConversations();
      }
    } catch (e) {
      alert('Error toggling visitor block state');
    }
  };

  // Load Platform Data (Super Admin)
  const loadPlatformData = async (token = authToken) => {
    if (!token) return;
    try {
      const [resStats, resTenants, resUsers] = await Promise.all([
        fetch(`${API_BASE}/platform/stats`, { headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' } }),
        fetch(`${API_BASE}/platform/tenants`, { headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' } }),
        fetch(`${API_BASE}/platform/users`, { headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' } })
      ]);
      const jsonStats = await resStats.json();
      const jsonTenants = await resTenants.json();
      const jsonUsers = await resUsers.json();
      if (jsonStats.success) setPlatformStats(jsonStats.data);
      if (jsonTenants.success) setPlatformTenants(jsonTenants.data);
      if (jsonUsers.success) setPlatformUsers(jsonUsers.data);
    } catch (e) {
      console.error(e);
    }
  };

  // Create Company Workspace (Super Admin)
  const handleCreateTenant = async (e) => {
    e.preventDefault();
    try {
      const res = await fetch(`${API_BASE}/platform/tenants`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          company_name: newCompany.name,
          admin_name: newCompany.adminName,
          admin_email: newCompany.adminEmail,
          admin_password: newCompany.adminPass,
          website_name: newCompany.siteName,
          website_url: newCompany.siteUrl,
          plan: newCompany.plan
        })
      });
      const json = await res.json();
      if (json.success) {
        setShowNewTenantModal(false);
        alert(`Success! Created ChatBox workspace for "${newCompany.name}".\n\nPublic Key: ${json.data.property.public_key}\nAdmin: ${newCompany.adminEmail}`);
        loadPlatformData();
      } else {
        alert(json.message || 'Failed to create company.');
      }
    } catch (e) {
      alert('Error connecting to backend.');
    }
  };

  // Update User Credentials (Super Admin)
  const handleUpdateUser = async () => {
    if (!editingUser) return;
    try {
      const payload = {
        name: editingUser.name,
        email: editingUser.email,
        role: editingUser.role
      };
      if (editingUser.password && editingUser.password.length >= 6) {
        payload.password = editingUser.password;
      }
      const res = await fetch(`${API_BASE}/platform/users/${editingUser.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${authToken}`,
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (json.success) {
        setShowEditUserModal(false);
        alert('User credentials updated successfully!');
        loadPlatformData();
      } else {
        alert(json.message || 'Failed to update user.');
      }
    } catch (e) {
      alert('Connection error.');
    }
  };

  // Embed script string
  const embedSnippet = currentProperty ? `<!-- ChatBox Live Support Widget -->
<script 
  src="${window?.location?.origin || 'http://localhost:8000'}/widget.js" 
  data-property-key="${currentProperty.public_key}" 
  async>
</script>` : '';

  // --------------------------------------------------------------------------
  // RENDER: LOGIN OVERLAY
  // --------------------------------------------------------------------------
  if (!authToken || !currentUser) {
    return (
      <div style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '20px'
      }}>
        <div className="skeuo-card" style={{ width: '100%', maxWidth: '440px', padding: '36px' }}>
          
          {/* Brand Header */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '14px', marginBottom: '28px' }}>
            <div style={{
              width: '48px',
              height: '48px',
              borderRadius: '14px',
              background: 'linear-gradient(135deg, #3b82f6, #1d4ed8)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: '22px',
              color: '#ffffff',
              boxShadow: '0 6px 14px rgba(37,99,235,0.35), inset 0 1px 0 rgba(255,255,255,0.6)'
            }}>
              💬
            </div>
            <div>
              <h1 style={{ fontSize: '22px', fontWeight: 800, color: '#0f172a', letterSpacing: '-0.5px' }}>
                ChatBox Workspace
              </h1>
              <p style={{ fontSize: '12.5px', color: '#64748b' }}>
                Tactile Skeuomorphic SaaS Live Support & CRM
              </p>
            </div>
          </div>

          {authError && (
            <div style={{
              background: '#fee2e2',
              border: '1px solid #f87171',
              color: '#991b1b',
              padding: '10px 14px',
              borderRadius: '10px',
              fontSize: '13px',
              marginBottom: '18px'
            }}>
              {authError}
            </div>
          )}

          {/* Login Form */}
          <form onSubmit={(e) => { e.preventDefault(); handleLogin(loginEmail, loginPassword); }}>
            <div style={{ marginBottom: '16px' }}>
              <label style={{ display: 'block', fontSize: '13px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
                Email Address
              </label>
              <input 
                type="email" 
                className="skeuo-input" 
                value={loginEmail} 
                onChange={(e) => setLoginEmail(e.target.value)} 
                required 
              />
            </div>

            <div style={{ marginBottom: '22px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                <label style={{ fontSize: '13px', fontWeight: 600, color: '#334155' }}>
                  Password
                </label>
                <button 
                  type="button" 
                  onClick={() => setShowForgotModal(true)}
                  style={{ background: 'none', border: 'none', color: '#2563eb', fontSize: '12px', fontWeight: 600, cursor: 'pointer' }}
                >
                  Forgot Password?
                </button>
              </div>
              <input 
                type="password" 
                className="skeuo-input" 
                value={loginPassword} 
                onChange={(e) => setLoginPassword(e.target.value)} 
                required 
              />
            </div>

            <button 
              type="submit" 
              className="skeuo-btn-primary" 
              style={{ width: '100%', padding: '13px' }} 
              disabled={isLoggingIn}
            >
              {isLoggingIn ? 'Signing In...' : 'Sign In to Workspace ➔'}
            </button>
          </form>

          {/* 1-Click Demo Accounts */}
          <div style={{ marginTop: '28px', paddingTop: '20px', borderTop: '1px solid rgba(203,213,225,0.6)' }}>
            <div style={{ fontSize: '11px', fontWeight: 700, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.6px', marginBottom: '10px' }}>
              ⚡ 1-Click Instant Demo Accounts
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
              <button 
                type="button" 
                className="skeuo-btn" 
                style={{ justifyContent: 'space-between', padding: '10px 14px' }}
                onClick={() => { setLoginEmail('ambitionmanagements@gmail.com'); setLoginPassword('password123'); handleLogin('ambitionmanagements@gmail.com', 'password123'); }}
              >
                <span>⚡ <strong>Super Admin</strong> (Platform Master)</span>
                <span className="skeuo-badge skeuo-badge-blue">ambition...</span>
              </button>
              <button 
                type="button" 
                className="skeuo-btn" 
                style={{ justifyContent: 'space-between', padding: '10px 14px' }}
                onClick={() => { setLoginEmail('admin@chatbox.com'); setLoginPassword('password123'); handleLogin('admin@chatbox.com', 'password123'); }}
              >
                <span>👑 <strong>Company Owner</strong> (Admin)</span>
                <span className="skeuo-badge skeuo-badge-orange">admin...</span>
              </button>
              <button 
                type="button" 
                className="skeuo-btn" 
                style={{ justifyContent: 'space-between', padding: '10px 14px' }}
                onClick={() => { setLoginEmail('rahul@chatbox.com'); setLoginPassword('password123'); handleLogin('rahul@chatbox.com', 'password123'); }}
              >
                <span>🎧 <strong>Support Agent</strong> (Rahul)</span>
                <span className="skeuo-badge skeuo-badge-green">rahul...</span>
              </button>
            </div>
          </div>
        </div>

        {/* Forgot Password Modal */}
        {showForgotModal && (
          <div style={{
            position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)', backdropFilter: 'blur(4px)',
            display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '20px'
          }}>
            <div className="skeuo-card" style={{ width: '100%', maxWidth: '400px', padding: '28px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
                <h3 style={{ fontSize: '17px', fontWeight: 700, color: '#0f172a' }}>🔑 Reset Password</h3>
                <button onClick={() => setShowForgotModal(false)} style={{ background: 'none', border: 'none', fontSize: '18px', cursor: 'pointer', color: '#64748b' }}>✕</button>
              </div>

              {forgotMsg.text && (
                <div style={{
                  padding: '10px 14px', borderRadius: '8px', fontSize: '13px', marginBottom: '14px',
                  background: forgotMsg.type === 'success' ? '#ecfdf5' : '#fee2e2',
                  color: forgotMsg.type === 'success' ? '#065f46' : '#991b1b',
                  border: `1px solid ${forgotMsg.type === 'success' ? '#a7f3d0' : '#f87171'}`
                }}>
                  {forgotMsg.text}
                </div>
              )}

              <form onSubmit={handleForgotPassword}>
                <div style={{ marginBottom: '14px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>
                    Registered Email
                  </label>
                  <input type="email" className="skeuo-input" required value={forgotEmail} onChange={e => setForgotEmail(e.target.value)} placeholder="your-email@example.com" />
                </div>
                <div style={{ marginBottom: '20px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>
                    New Password (min 6 characters)
                  </label>
                  <input type="password" className="skeuo-input" required value={forgotPassword} onChange={e => setForgotPassword(e.target.value)} placeholder="••••••••" />
                </div>
                <button type="submit" className="skeuo-btn-primary" style={{ width: '100%', background: 'linear-gradient(180deg, #f97316, #ea580c)' }}>
                  Reset & Update Password
                </button>
              </form>
            </div>
          </div>
        )}
      </div>
    );
  }

  // --------------------------------------------------------------------------
  // RENDER: MAIN APPLICATION WORKSPACE
  // --------------------------------------------------------------------------
  return (
    <div style={{ display: 'flex', height: '100vh', overflow: 'hidden' }}>

      {/* Real-time Alert Toast */}
      {alertToast && (
        <div style={{
          position: 'fixed', top: '20px', right: '20px', zIndex: 9999,
          background: 'linear-gradient(145deg, #ffffff, #eff6ff)',
          border: '1px solid #bfdbfe', borderRadius: '14px',
          boxShadow: '0 10px 25px -5px rgba(29, 78, 216, 0.3), inset 0 1px 0 #ffffff',
          padding: '14px 18px', display: 'flex', alignItems: 'center', gap: '12px',
          animation: 'skeuo-pulse 2s infinite ease-in-out'
        }}>
          <div className="skeuo-led skeuo-led-green"></div>
          <div>
            <div style={{ fontSize: '13px', fontWeight: 700, color: '#1e40af' }}>{alertToast.title}</div>
            <div style={{ fontSize: '12px', color: '#475569' }}>{alertToast.message}</div>
          </div>
          <button onClick={() => setAlertToast(null)} style={{ background: 'none', border: 'none', color: '#94a3b8', cursor: 'pointer', fontSize: '16px', marginLeft: '6px' }}>✕</button>
        </div>
      )}

      {/* Navigation Rail (Column 1) */}
      <aside style={{
        width: '260px',
        background: 'linear-gradient(180deg, #f8fafc 0%, #eef2f6 100%)',
        borderRight: '1px solid rgba(203, 213, 225, 0.7)',
        boxShadow: '4px 0 12px rgba(0,0,0,0.03), inset -1px 0 0 rgba(255,255,255,0.8)',
        display: 'flex',
        flexDirection: 'column',
        flexShrink: 0
      }}>
        {/* Rail Header */}
        <div style={{ padding: '20px', display: 'flex', alignItems: 'center', gap: '12px', borderBottom: '1px solid rgba(203,213,225,0.6)' }}>
          <div style={{
            width: '40px', height: '40px', borderRadius: '12px',
            background: 'linear-gradient(135deg, #3b82f6, #1d4ed8)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: '18px', color: '#ffffff',
            boxShadow: '0 4px 10px rgba(37,99,235,0.35), inset 0 1px 0 rgba(255,255,255,0.6)'
          }}>
            💬
          </div>
          <div>
            <div style={{ fontSize: '16px', fontWeight: 800, color: '#0f172a' }}>ChatBox</div>
            <div style={{ fontSize: '11px', color: '#64748b' }}>
              {currentProperty?.site_name || 'Live Workspace'}
            </div>
          </div>
        </div>

        {/* Rail Menu Items */}
        <nav style={{ padding: '16px 12px', flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '6px' }}>
          
          {/* Super Admin Section */}
          {currentUser.role === 'super_admin' && (
            <div>
              <div style={{ fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.6px', padding: '6px 10px 4px' }}>
                ⚡ SaaS Master
              </div>
              <button 
                type="button" 
                className={`skeuo-btn ${activeView === 'platform' ? 'active' : ''}`}
                style={{ width: '100%', justifyContent: 'flex-start', marginBottom: '8px' }}
                onClick={() => { setActiveView('platform'); loadPlatformData(); }}
              >
                ⚡ Platform Master Control
              </button>
            </div>
          )}

          <div style={{ fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.6px', padding: '6px 10px 4px' }}>
            Live Operations
          </div>

          <button 
            type="button" 
            className={`skeuo-btn ${activeView === 'inbox' ? 'active' : ''}`}
            style={{ width: '100%', justifyContent: 'space-between' }}
            onClick={() => setActiveView('inbox')}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              💬 Live Chat Inbox
            </span>
            {unreadCount > 0 && (
              <span className="skeuo-badge skeuo-badge-orange">{unreadCount}</span>
            )}
          </button>

          {/* CRM LEADS HUB */}
          <button 
            type="button" 
            className={`skeuo-btn ${activeView === 'leads' ? 'active' : ''}`}
            style={{ width: '100%', justifyContent: 'space-between' }}
            onClick={() => { setActiveView('leads'); loadLeads(); }}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              🎯 CRM Leads Hub
            </span>
            <span className="skeuo-badge skeuo-badge-blue">{leads.length}</span>
          </button>

          {/* COMPANY FAQS & KNOWLEDGE BASE */}
          <button 
            type="button" 
            className={`skeuo-btn ${activeView === 'faqs' ? 'active' : ''}`}
            style={{ width: '100%', justifyContent: 'space-between' }}
            onClick={() => { setActiveView('faqs'); loadFaqs(); }}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              📚 Knowledge Base & FAQs
            </span>
            <span className="skeuo-badge skeuo-badge-purple">{faqs.length}</span>
          </button>

          {/* Settings Section (Hidden for frontline Agents) */}
          {currentUser.role !== 'agent' && (
            <>
              <div style={{ fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.6px', padding: '10px 10px 4px' }}>
                Settings & Integration
              </div>
              <button 
                type="button" 
                className={`skeuo-btn ${activeView === 'customizer' ? 'active' : ''}`}
                style={{ width: '100%', justifyContent: 'flex-start' }}
                onClick={() => setActiveView('customizer')}
              >
                🎨 Widget & Embed Code
              </button>
              <button 
                type="button" 
                className={`skeuo-btn ${activeView === 'team' ? 'active' : ''}`}
                style={{ width: '100%', justifyContent: 'flex-start' }}
                onClick={() => setActiveView('team')}
              >
                👥 Support Team
              </button>
            </>
          )}

          <div style={{ fontSize: '11px', fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.6px', padding: '10px 10px 4px' }}>
            Website Demo
          </div>
          <a 
            href="http://localhost:8000/demo" 
            target="_blank" 
            rel="noreferrer" 
            className="skeuo-btn" 
            style={{ width: '100%', justifyContent: 'space-between', textDecoration: 'none' }}
          >
            <span>🚀 Open Client Website</span>
            <span>↗</span>
          </a>
        </nav>

        {/* Rail User Profile Footer */}
        <div style={{
          padding: '16px', borderTop: '1px solid rgba(203,213,225,0.6)',
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          background: 'linear-gradient(180deg, #ffffff, #edf2f7)'
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div style={{
              width: '36px', height: '36px', borderRadius: '50%',
              background: 'linear-gradient(135deg, #1e293b, #334155)',
              color: '#ffffff', display: 'flex', alignItems: 'center', justifyContent: 'center',
              fontWeight: 700, fontSize: '13px', position: 'relative'
            }}>
              {currentUser.name.slice(0, 2).toUpperCase()}
              <div className="skeuo-led skeuo-led-green" style={{ position: 'absolute', bottom: '-1px', right: '-1px', border: '2px solid #ffffff' }}></div>
            </div>
            <div>
              <div style={{ fontSize: '13px', fontWeight: 700, color: '#0f172a' }}>{currentUser.name}</div>
              <div style={{ fontSize: '11px', color: '#64748b', textTransform: 'capitalize' }}>
                {currentUser.role.replace('_', ' ')}
              </div>
            </div>
          </div>
          <button 
            type="button" 
            className="skeuo-btn" 
            style={{ padding: '6px 10px', fontSize: '13px' }} 
            onClick={handleLogout}
            title="Sign Out"
          >
            ⏻
          </button>
        </div>
      </aside>

      {/* ====================================================================
          VIEW 1: LIVE CHAT INBOX (3-Column Workspace)
          ==================================================================== */}
      {activeView === 'inbox' && (
        <div style={{ flex: 1, display: 'flex', overflow: 'hidden' }}>
          
          {/* Column 2: Conversations Feed */}
          <section style={{
            width: '320px',
            background: 'linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%)',
            borderRight: '1px solid rgba(203,213,225,0.7)',
            display: 'flex',
            flexDirection: 'column',
            flexShrink: 0
          }}>
            {/* Feed Header & Search */}
            <div style={{ padding: '14px', borderBottom: '1px solid rgba(203,213,225,0.6)' }}>
              <input 
                type="text" 
                className="skeuo-input" 
                placeholder="Search conversations..." 
                value={searchQuery}
                onChange={e => setSearchQuery(e.target.value)}
                style={{ padding: '8px 12px', fontSize: '13px', marginBottom: '10px' }}
              />
              <div style={{ display: 'flex', gap: '4px' }}>
                {['unassigned', 'mine', 'all', 'closed'].map(f => (
                  <button 
                    key={f} 
                    type="button"
                    className={`skeuo-btn ${filter === f ? 'active' : ''}`}
                    style={{ flex: 1, padding: '5px 4px', fontSize: '11.5px', textTransform: 'capitalize' }}
                    onClick={() => setFilter(f)}
                  >
                    {f}
                  </button>
                ))}
              </div>
            </div>

            {/* Conversation Items List */}
            <div style={{ flex: 1, overflowY: 'auto', padding: '8px' }}>
              {conversations.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '40px 14px', color: '#94a3b8', fontSize: '13px' }}>
                  No active conversations in this tab.
                </div>
              ) : (
                conversations
                  .filter(c => !searchQuery || (c.visitor?.name || '').toLowerCase().includes(searchQuery.toLowerCase()))
                  .map(c => {
                    const isSelected = activeConv?.id === c.id;
                    return (
                      <div 
                        key={c.id} 
                        onClick={() => selectConversation(c)}
                        className={`skeuo-card`}
                        style={{
                          padding: '12px', marginBottom: '8px', cursor: 'pointer',
                          background: isSelected ? 'linear-gradient(145deg, #eff6ff, #dbeafe)' : undefined,
                          borderColor: isSelected ? '#93c5fd' : undefined
                        }}
                      >
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '4px' }}>
                          <span style={{ fontSize: '13.5px', fontWeight: 700, color: '#0f172a' }}>
                            {c.visitor?.name || 'Anonymous Visitor'}
                          </span>
                          <span style={{ fontSize: '10.5px', color: '#64748b' }}>
                            {c.last_message_at ? new Date(c.last_message_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                          </span>
                        </div>
                        <div style={{
                          fontSize: '12.5px', color: '#475569',
                          overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', marginBottom: '6px'
                        }}>
                          {c.latest_message?.content || 'No message yet'}
                        </div>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                          <span className={`skeuo-badge ${c.status === 'unassigned' ? 'skeuo-badge-orange' : (c.status === 'closed' ? 'skeuo-badge-purple' : 'skeuo-badge-blue')}`}>
                            {c.status}
                          </span>
                          {c.unread_count > 0 && (
                            <span className="skeuo-badge skeuo-badge-orange">{c.unread_count} new</span>
                          )}
                        </div>
                      </div>
                    );
                  })
              )}
            </div>
          </section>

          {/* Column 3: Active Chat Main Stream */}
          <main style={{ flex: 1, display: 'flex', flexDirection: 'column', background: '#f8fafc', overflow: 'hidden' }}>
            {activeConv ? (
              <>
                {/* Chat Stream Header */}
                <header style={{
                  padding: '14px 20px', borderBottom: '1px solid rgba(203,213,225,0.7)',
                  background: 'linear-gradient(180deg, #ffffff 0%, #f1f5f9 100%)',
                  display: 'flex', justifyContent: 'space-between', alignItems: 'center',
                  boxShadow: '0 2px 4px rgba(0,0,0,0.02)'
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <div className="skeuo-led skeuo-led-green"></div>
                    <div>
                      <div style={{ fontSize: '15px', fontWeight: 700, color: '#0f172a' }}>
                        {activeConv.visitor?.name || 'Visitor'}
                      </div>
                      <div style={{ fontSize: '11.5px', color: '#64748b' }}>
                        {activeConv.visitor?.email || 'No email provided'} • {activeConv.department?.name || 'General Support'}
                      </div>
                    </div>
                  </div>
                  <div style={{ display: 'flex', gap: '8px' }}>
                    {activeConv.status === 'unassigned' && (
                      <button type="button" className="skeuo-btn-primary" style={{ padding: '6px 14px', fontSize: '12px' }} onClick={handleAssignToMe}>
                        Assign to Me
                      </button>
                    )}
                    {activeConv.status !== 'closed' && (
                      <button type="button" className="skeuo-btn" style={{ padding: '6px 14px', fontSize: '12px' }} onClick={handleCloseConversation}>
                        ✓ Close Ticket
                      </button>
                    )}
                  </div>
                </header>

                {/* Messages Stream Container */}
                <div className="skeuo-inset" style={{
                  flex: 1, margin: '14px', padding: '16px', overflowY: 'auto',
                  display: 'flex', flexDirection: 'column', gap: '12px'
                }}>
                  {(activeConv.messages || []).map(m => {
                    const isVisitor = m.sender_type === 'visitor';
                    return (
                      <div 
                        key={m.id}
                        style={{
                          display: 'flex',
                          justifyContent: isVisitor ? 'flex-start' : 'flex-end',
                          alignItems: 'flex-end',
                          gap: '8px'
                        }}
                      >
                        <div style={{
                          maxWidth: '70%',
                          padding: '10px 15px',
                          borderRadius: isVisitor ? '14px 14px 14px 2px' : '14px 14px 2px 14px',
                          background: isVisitor 
                            ? 'linear-gradient(145deg, #ffffff, #f1f5f9)' 
                            : 'linear-gradient(145deg, #3b82f6, #1d4ed8)',
                          color: isVisitor ? '#0f172a' : '#ffffff',
                          boxShadow: isVisitor 
                            ? '2px 3px 8px rgba(0,0,0,0.06), inset 0 1px 0 #ffffff'
                            : '0 4px 10px rgba(29, 78, 216, 0.3), inset 0 1px 0 rgba(255,255,255,0.4)',
                          fontSize: '13.5px',
                          lineHeight: '1.45'
                        }}>
                          <div>{m.content}</div>
                          <div style={{
                            fontSize: '10px',
                            opacity: 0.7,
                            textAlign: 'right',
                            marginTop: '4px'
                          }}>
                            {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>

                {/* Reply Input Bar with Slash Command popup */}
                <div style={{ padding: '14px 20px', borderTop: '1px solid rgba(203,213,225,0.7)', background: '#ffffff', position: 'relative' }}>
                  
                  {/* Canned Responses Autocomplete Popup */}
                  {showCannedMenu && cannedResponses.length > 0 && (
                    <div className="skeuo-card" style={{
                      position: 'absolute', bottom: '70px', left: '20px', right: '20px',
                      padding: '10px', zIndex: 100
                    }}>
                      <div style={{ fontSize: '11px', fontWeight: 700, color: '#64748b', marginBottom: '6px' }}>
                        ⚡ Slash Commands (Canned Responses)
                      </div>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                        {cannedResponses.map(cr => (
                          <div 
                            key={cr.id}
                            style={{ padding: '7px 10px', borderRadius: '8px', cursor: 'pointer', display: 'flex', gap: '10px', fontSize: '13px', background: '#f8fafc' }}
                            onClick={() => { setReplyText(cr.content); setShowCannedMenu(false); }}
                          >
                            <span style={{ fontWeight: 700, color: '#2563eb' }}>{cr.shortcut}</span>
                            <span style={{ color: '#475569', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{cr.content}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Quick FAQ Answers Picker Popup */}
                  {showFaqQuickPicker && (
                    <div className="skeuo-card" style={{
                      position: 'absolute', bottom: '70px', left: '20px', right: '20px',
                      padding: '14px', zIndex: 100, maxHeight: '280px', overflowY: 'auto'
                    }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                        <div style={{ fontSize: '11px', fontWeight: 700, color: '#4338ca', textTransform: 'uppercase' }}>
                          📚 Company Knowledge Base — 1-Click Reply
                        </div>
                        <button type="button" onClick={() => setShowFaqQuickPicker(false)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}>✕</button>
                      </div>
                      {faqs.length === 0 ? (
                        <div style={{ fontSize: '12px', color: '#94a3b8', padding: '10px' }}>No FAQs added yet. Add FAQs in the Knowledge Base tab!</div>
                      ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '6px' }}>
                          {faqs.map(faq => (
                            <div 
                              key={faq.id} 
                              style={{
                                padding: '8px 12px', borderRadius: '8px', background: '#f8fafc',
                                border: '1px solid #e2e8f0', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '10px'
                              }}
                            >
                              <div style={{ overflow: 'hidden' }}>
                                <div style={{ fontWeight: 700, fontSize: '12.5px', color: '#0f172a' }}>{faq.question}</div>
                                <div style={{ fontSize: '11.5px', color: '#64748b', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{faq.answer}</div>
                              </div>
                              <button 
                                type="button" 
                                className="skeuo-btn-primary" 
                                style={{ fontSize: '11px', padding: '5px 10px', whiteSpace: 'nowrap' }}
                                onClick={() => {
                                  setReplyText(faq.answer);
                                  setShowFaqQuickPicker(false);
                                }}
                              >
                                ↳ Insert Answer
                              </button>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  )}

                  <div style={{ display: 'flex', gap: '10px' }}>
                    <input 
                      type="text" 
                      className="skeuo-input" 
                      placeholder="Type a message... (Tip: type / for canned responses)" 
                      value={replyText}
                      onChange={e => {
                        setReplyText(e.target.value);
                        if (e.target.value.startsWith('/')) setShowCannedMenu(true);
                        else setShowCannedMenu(false);
                      }}
                      onKeyDown={e => { if (e.key === 'Enter') handleSendMessage(); }}
                    />
                    <button 
                      type="button" 
                      className="skeuo-btn" 
                      title="Quick FAQ Answers"
                      style={{ padding: '0 14px', fontSize: '13px', display: 'flex', alignItems: 'center', gap: '6px' }}
                      onClick={() => { setShowFaqQuickPicker(!showFaqQuickPicker); loadFaqs(); }}
                    >
                      <span>📚 FAQs</span>
                    </button>
                    <button 
                      type="button" 
                      className="skeuo-btn-primary" 
                      onClick={handleSendMessage}
                    >
                      Send ➤
                    </button>
                  </div>
                </div>
              </>
            ) : (
              <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: '12px', color: '#94a3b8' }}>
                <div style={{ fontSize: '42px' }}>💬</div>
                <div style={{ fontSize: '16px', fontWeight: 600 }}>Select a conversation from the feed to start chatting</div>
              </div>
            )}
          </main>

          {/* Column 4: Visitor Telemetry / Intel Panel */}
          {activeConv && (
            <aside style={{
              width: '260px',
              background: 'linear-gradient(180deg, #f8fafc 0%, #edf2f7 100%)',
              borderLeft: '1px solid rgba(203,213,225,0.7)',
              padding: '18px',
              display: 'flex',
              flexDirection: 'column',
              gap: '14px',
              flexShrink: 0
            }}>
              <div style={{ fontSize: '12px', fontWeight: 700, color: '#475569', textTransform: 'uppercase', letterSpacing: '0.6px' }}>
                Visitor Telemetry
              </div>
              <div className="skeuo-card" style={{ padding: '14px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '10px' }}>
                  <div className={`skeuo-led ${activeConv.visitor?.is_blocked ? 'skeuo-led-orange' : 'skeuo-led-green'}`}></div>
                  <span style={{ fontSize: '12px', fontWeight: 700, color: activeConv.visitor?.is_blocked ? '#dc2626' : '#166534' }}>
                    {activeConv.visitor?.is_blocked ? 'Blocked' : 'Online Now'}
                  </span>
                </div>
                <div style={{ fontSize: '12px', color: '#64748b', display: 'flex', flexDirection: 'column', gap: '8px' }}>
                  <div><strong>Name:</strong> {activeConv.visitor?.name || 'Anonymous'}</div>
                  <div><strong>Email:</strong> {activeConv.visitor?.email || 'N/A'}</div>
                  <div><strong>Location:</strong> {activeConv.visitor?.city || 'Localhost'}, {activeConv.visitor?.country_code || 'IN'}</div>
                  <div><strong>IP Address:</strong> {activeConv.visitor?.ip_address || '127.0.0.1'}</div>
                  <div><strong>Browser / OS:</strong> {activeConv.visitor?.browser || 'Chrome'} ({activeConv.visitor?.os || 'Windows'})</div>
                  <div><strong>Page Visited:</strong> <span style={{ wordBreak: 'break-all' }}>{activeConv.visitor?.current_page_url || '/demo'}</span></div>
                </div>

                {/* Visitor Blocking Action */}
                <div style={{ marginTop: '16px', paddingTop: '12px', borderTop: '1px solid #e2e8f0' }}>
                  <button 
                    type="button" 
                    className="skeuo-btn" 
                    style={{
                      width: '100%', fontSize: '11.5px', padding: '8px',
                      background: activeConv.visitor?.is_blocked ? '#fee2e2' : '#ffffff',
                      color: activeConv.visitor?.is_blocked ? '#991b1b' : '#b91c1c',
                      borderColor: '#fca5a5'
                    }}
                    onClick={() => handleToggleBlockVisitor(activeConv.visitor?.id)}
                  >
                    {activeConv.visitor?.is_blocked ? '✓ Unblock Visitor' : '🚫 Block Visitor / IP'}
                  </button>
                </div>
              </div>
            </aside>
          )}

        </div>
      )}

      {/* ====================================================================
          VIEW 2: 🎯 CRM LEADS HUB (NEW FEATURE)
          ==================================================================== */}
      {activeView === 'leads' && (
        <main style={{ flex: 1, padding: '28px', overflowY: 'auto' }}>
          
          {/* Header */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '22px' }}>
            <div>
              <h2 style={{ fontSize: '24px', fontWeight: 800, color: '#0f172a', letterSpacing: '-0.5px' }}>
                🎯 CRM Leads & Form Submissions
              </h2>
              <p style={{ fontSize: '13px', color: '#64748b' }}>
                Every visitor who starts a chat or submits contact details is automatically captured as a qualified lead.
              </p>
            </div>
            <div style={{ display: 'flex', gap: '10px' }}>
              <button type="button" className="skeuo-btn" onClick={exportLeadsCsv}>
                📥 Export CSV
              </button>
              <button type="button" className="skeuo-btn-primary" onClick={loadLeads}>
                🔄 Refresh Leads
              </button>
            </div>
          </div>

          {/* Filter Bar */}
          <div className="skeuo-card" style={{ padding: '14px 18px', marginBottom: '20px', display: 'flex', gap: '14px', alignItems: 'center' }}>
            <input 
              type="text" 
              className="skeuo-input" 
              placeholder="Search leads by name, email, phone, or city..."
              value={leadSearch}
              onChange={e => setLeadSearch(e.target.value)}
              style={{ maxWidth: '380px' }}
            />
            <div style={{ display: 'flex', gap: '6px' }}>
              {['all', 'new', 'contacted', 'qualified', 'converted'].map(s => (
                <button 
                  key={s} 
                  type="button" 
                  className={`skeuo-btn ${leadStatusFilter === s ? 'active' : ''}`}
                  style={{ textTransform: 'capitalize', fontSize: '12px', padding: '7px 12px' }}
                  onClick={() => setLeadStatusFilter(s)}
                >
                  {s}
                </button>
              ))}
            </div>
          </div>

          {/* Leads Table */}
          <div className="skeuo-card" style={{ overflow: 'hidden' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
              <thead>
                <tr style={{ background: '#f1f5f9', borderBottom: '1px solid rgba(203,213,225,0.7)' }}>
                  <th style={{ padding: '12px 16px', fontWeight: 700, color: '#475569' }}>Lead Name</th>
                  <th style={{ padding: '12px 16px', fontWeight: 700, color: '#475569' }}>Email & Phone</th>
                  <th style={{ padding: '12px 16px', fontWeight: 700, color: '#475569' }}>Status</th>
                  <th style={{ padding: '12px 16px', fontWeight: 700, color: '#475569' }}>Initial Inquiry</th>
                  <th style={{ padding: '12px 16px', fontWeight: 700, color: '#475569' }}>Location & Page</th>
                  <th style={{ padding: '12px 16px', fontWeight: 700, color: '#475569' }}>Date</th>
                  <th style={{ padding: '12px 16px', fontWeight: 700, color: '#475569', textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {leads.length === 0 ? (
                  <tr>
                    <td colSpan={7} style={{ padding: '36px', textAlign: 'center', color: '#94a3b8' }}>
                      No leads captured yet. As visitors start chatting on your client sites, they will appear here!
                    </td>
                  </tr>
                ) : (
                  leads.map(lead => {
                    const statusClass = lead.lead_status === 'converted' ? 'skeuo-badge-green' :
                                        (lead.lead_status === 'qualified' ? 'skeuo-badge-purple' :
                                        (lead.lead_status === 'contacted' ? 'skeuo-badge-blue' : 'skeuo-badge-orange'));
                    return (
                      <tr key={lead.id} style={{ borderBottom: '1px solid rgba(203,213,225,0.4)' }}>
                        <td style={{ padding: '12px 16px', fontWeight: 700, color: '#0f172a' }}>
                          {lead.name}
                        </td>
                        <td style={{ padding: '12px 16px', color: '#334155' }}>
                          <div>{lead.email || '—'}</div>
                          {lead.phone && <div style={{ fontSize: '11px', color: '#64748b' }}>📞 {lead.phone}</div>}
                        </td>
                        <td style={{ padding: '12px 16px' }}>
                          <span className={`skeuo-badge ${statusClass}`}>
                            {lead.lead_status}
                          </span>
                        </td>
                        <td style={{ padding: '12px 16px', maxWidth: '280px', color: '#475569' }}>
                          <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {lead.initial_inquiry}
                          </div>
                        </td>
                        <td style={{ padding: '12px 16px', color: '#64748b', fontSize: '12px' }}>
                          <div>📍 {lead.city || 'Unknown'}</div>
                          <div style={{ fontSize: '11px' }}>{lead.current_page_url || '/'}</div>
                        </td>
                        <td style={{ padding: '12px 16px', color: '#64748b', fontSize: '12px' }}>
                          {lead.created_at ? new Date(lead.created_at).toLocaleDateString() : '—'}
                        </td>
                        <td style={{ padding: '12px 16px', textAlign: 'right' }}>
                          <button 
                            type="button" 
                            className="skeuo-btn" 
                            style={{ padding: '5px 10px', fontSize: '12px' }}
                            onClick={() => {
                              setSelectedLead(lead);
                              setEditLeadStatus(lead.lead_status || 'new');
                              setEditLeadNotes(lead.lead_notes || '');
                              setShowEditLeadModal(true);
                            }}
                          >
                            ✏️ Update Status
                          </button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Edit Lead Status Modal */}
          {showEditLeadModal && selectedLead && (
            <div style={{
              position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)', backdropFilter: 'blur(4px)',
              display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '20px'
            }}>
              <div className="skeuo-card" style={{ width: '100%', maxWidth: '440px', padding: '28px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
                  <h3 style={{ fontSize: '17px', fontWeight: 700, color: '#0f172a' }}>
                    ✏️ Update Lead: {selectedLead.name}
                  </h3>
                  <button onClick={() => setShowEditLeadModal(false)} style={{ background: 'none', border: 'none', fontSize: '18px', cursor: 'pointer', color: '#64748b' }}>✕</button>
                </div>

                <div style={{ marginBottom: '16px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
                    Lead Lifecycle Status
                  </label>
                  <select 
                    className="skeuo-input" 
                    value={editLeadStatus} 
                    onChange={e => setEditLeadStatus(e.target.value)}
                  >
                    <option value="new">🟡 New Inquiry</option>
                    <option value="contacted">🔵 Contacted by Agent</option>
                    <option value="qualified">🟣 Qualified Opportunity</option>
                    <option value="converted">🟢 Converted / Customer</option>
                    <option value="lost">⚪ Lost / Closed</option>
                  </select>
                </div>

                <div style={{ marginBottom: '22px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
                    CRM Internal Notes
                  </label>
                  <textarea 
                    className="skeuo-input" 
                    rows={4} 
                    placeholder="Add follow-up notes, deal value, or call summary..."
                    value={editLeadNotes} 
                    onChange={e => setEditLeadNotes(e.target.value)}
                  />
                </div>

                <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                  <button type="button" className="skeuo-btn" onClick={() => setShowEditLeadModal(false)}>
                    Cancel
                  </button>
                  <button type="button" className="skeuo-btn-primary" onClick={handleUpdateLead}>
                    Save Lead Details
                  </button>
                </div>
              </div>
            </div>
          )}

        </main>
      )}

      {/* ====================================================================
          VIEW: 📚 COMPANY FAQS & KNOWLEDGE BASE (SELF-SERVICE & FALLBACK)
          ==================================================================== */}
      {activeView === 'faqs' && (
        <main style={{ flex: 1, padding: '28px', overflowY: 'auto' }}>
          
          {/* Header */}
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '22px' }}>
            <div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <h2 style={{ fontSize: '24px', fontWeight: 800, color: '#0f172a', letterSpacing: '-0.5px' }}>
                  📚 Company Knowledge Base & FAQs
                </h2>
                {currentProperty && (
                  <span className="skeuo-badge skeuo-badge-blue" style={{ fontSize: '12px' }}>
                    {currentProperty.site_name}
                  </span>
                )}
              </div>
              <p style={{ fontSize: '13px', color: '#64748b', marginTop: '4px' }}>
                Provide instant self-service answers to visitors. If an answer is missing, visitors can handoff to live chat with 1 tap.
              </p>
            </div>
            <div style={{ display: 'flex', gap: '10px' }}>
              <button type="button" className="skeuo-btn" onClick={loadFaqs}>
                🔄 Refresh
              </button>
              <button type="button" className="skeuo-btn-primary" onClick={() => setShowFaqModal(true)}>
                + Add Company FAQ
              </button>
            </div>
          </div>

          {/* Fallback Workflow Explanation Banner */}
          <div className="skeuo-card" style={{ padding: '16px 20px', marginBottom: '22px', background: 'linear-gradient(145deg, #f0fdf4, #dcfce7)', border: '1px solid #86efac' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '6px' }}>
              <span style={{ fontSize: '18px' }}>💡</span>
              <strong style={{ fontSize: '14px', color: '#166534' }}>How FAQ Self-Service &amp; Live Chat Fallback Works:</strong>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '14px', fontSize: '12.5px', color: '#14532d', marginTop: '8px' }}>
              <div style={{ background: 'rgba(255,255,255,0.7)', padding: '10px 12px', borderRadius: '8px' }}>
                <strong>1. Instant Visitor Answers:</strong> Website visitors see these FAQs inside the chat widget immediately without waiting in queue.
              </div>
              <div style={{ background: 'rgba(255,255,255,0.7)', padding: '10px 12px', borderRadius: '8px' }}>
                <strong>2. Seamless Live Chat Fallback:</strong> If the visitor needs more details, they click &ldquo;Start Live Chat&rdquo; and transfer directly to an agent.
              </div>
              <div style={{ background: 'rgba(255,255,255,0.7)', padding: '10px 12px', borderRadius: '8px' }}>
                <strong>3. Agent 1-Click Response:</strong> When agents don&rsquo;t know an answer, they click &ldquo;📚 FAQs&rdquo; in the chat inbox to paste standard verified replies.
              </div>
            </div>
          </div>

          {/* Filter & Property Switcher */}
          <div className="skeuo-card" style={{ padding: '14px 18px', marginBottom: '20px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '14px' }}>
            <input 
              type="text" 
              className="skeuo-input" 
              placeholder="Search FAQs by question, category, or answer keywords..."
              value={faqSearch}
              onChange={e => setFaqSearch(e.target.value)}
              style={{ maxWidth: '420px' }}
            />

            {allProperties.length > 1 && (
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <span style={{ fontSize: '12px', fontWeight: 600, color: '#475569' }}>Workspace Site:</span>
                <select 
                  className="skeuo-input" 
                  style={{ width: 'auto', padding: '6px 12px', fontSize: '12px' }}
                  value={currentProperty?.id}
                  onChange={e => {
                    const sel = allProperties.find(p => p.id === e.target.value);
                    if (sel) {
                      setCurrentProperty(sel);
                    }
                  }}
                >
                  {allProperties.map(p => (
                    <option key={p.id} value={p.id}>{p.site_name} ({p.domain || 'All'})</option>
                  ))}
                </select>
              </div>
            )}
          </div>

          {/* FAQs List */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
            {faqs
              .filter(f => !faqSearch || f.question.toLowerCase().includes(faqSearch.toLowerCase()) || f.answer.toLowerCase().includes(faqSearch.toLowerCase()) || f.category.toLowerCase().includes(faqSearch.toLowerCase()))
              .length === 0 ? (
                <div className="skeuo-card" style={{ padding: '40px', textAlign: 'center', color: '#94a3b8' }}>
                  <div style={{ fontSize: '36px', marginBottom: '10px' }}>📚</div>
                  <div style={{ fontWeight: 700, fontSize: '15px', color: '#475569' }}>No FAQs found</div>
                  <div style={{ fontSize: '13px', marginTop: '4px' }}>Add common questions and answers so your visitors can get instant help anytime.</div>
                </div>
              ) : (
                faqs
                  .filter(f => !faqSearch || f.question.toLowerCase().includes(faqSearch.toLowerCase()) || f.answer.toLowerCase().includes(faqSearch.toLowerCase()) || f.category.toLowerCase().includes(faqSearch.toLowerCase()))
                  .map(faq => (
                    <div key={faq.id} className="skeuo-card" style={{ padding: '18px 22px' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '14px', marginBottom: '8px' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                          <span className="skeuo-badge skeuo-badge-blue" style={{ fontSize: '11px', textTransform: 'uppercase' }}>
                            {faq.category || 'General'}
                          </span>
                          <h4 style={{ fontSize: '15px', fontWeight: 800, color: '#0f172a' }}>
                            {faq.question}
                          </h4>
                        </div>
                        <button 
                          type="button" 
                          className="skeuo-btn" 
                          style={{ fontSize: '11.5px', padding: '5px 10px', color: '#dc2626', borderColor: '#fca5a5' }}
                          onClick={() => handleDeleteFaq(faq.id)}
                        >
                          🗑️ Delete
                        </button>
                      </div>
                      <div style={{ fontSize: '13px', color: '#334155', lineHeight: 1.6, paddingLeft: '2px', whiteSpace: 'pre-line' }}>
                        {faq.answer}
                      </div>
                    </div>
                  ))
              )}
          </div>

          {/* Modal: Add New FAQ */}
          {showFaqModal && (
            <div style={{
              position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)', backdropFilter: 'blur(4px)',
              display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '20px'
            }}>
              <div className="skeuo-card" style={{ width: '100%', maxWidth: '480px', padding: '28px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
                  <h3 style={{ fontSize: '18px', fontWeight: 800, color: '#0f172a' }}>
                    + Add Company FAQ / Quick Answer
                  </h3>
                  <button onClick={() => setShowFaqModal(false)} style={{ background: 'none', border: 'none', fontSize: '18px', cursor: 'pointer', color: '#64748b' }}>✕</button>
                </div>

                <form onSubmit={handleCreateFaq}>
                  <div style={{ marginBottom: '14px' }}>
                    <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>
                      Category / Topic
                    </label>
                    <select 
                      className="skeuo-input" 
                      value={newFaq.category} 
                      onChange={e => setNewFaq({ ...newFaq, category: e.target.value })}
                    >
                      <option value="General">General / Overview</option>
                      <option value="Pricing">Pricing & Plans</option>
                      <option value="Support">Customer & Technical Support</option>
                      <option value="Features">Features & Capabilities</option>
                      <option value="Billing">Billing & Refunds</option>
                    </select>
                  </div>

                  <div style={{ marginBottom: '14px' }}>
                    <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>
                      Question (Visitor Inquiry)
                    </label>
                    <input 
                      type="text" 
                      className="skeuo-input" 
                      required 
                      placeholder="e.g. What are your operating hours?" 
                      value={newFaq.question} 
                      onChange={e => setNewFaq({ ...newFaq, question: e.target.value })} 
                    />
                  </div>

                  <div style={{ marginBottom: '22px' }}>
                    <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>
                      Answer (Self-Service & Agent Reply)
                    </label>
                    <textarea 
                      className="skeuo-input" 
                      rows={5} 
                      required 
                      placeholder="Provide a clear, helpful response. This answer will be available on the visitor widget and to live chat agents." 
                      value={newFaq.answer} 
                      onChange={e => setNewFaq({ ...newFaq, answer: e.target.value })} 
                    />
                  </div>

                  <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                    <button type="button" className="skeuo-btn" onClick={() => setShowFaqModal(false)}>
                      Cancel
                    </button>
                    <button type="submit" className="skeuo-btn-primary">
                      ✓ Save FAQ
                    </button>
                  </div>
                </form>
              </div>
            </div>
          )}

        </main>
      )}

      {/* ====================================================================
          VIEW 3: ⚡ SUPER ADMIN PLATFORM MASTER CONTROL
          ==================================================================== */}
      {activeView === 'platform' && currentUser.role === 'super_admin' && (
        <main style={{ flex: 1, padding: '28px', overflowY: 'auto' }}>
          
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px' }}>
            <div>
              <h2 style={{ fontSize: '24px', fontWeight: 800, color: '#0f172a', letterSpacing: '-0.5px' }}>
                ⚡ Platform Master Control Center
              </h2>
              <p style={{ fontSize: '13px', color: '#64748b' }}>
                Master administrative oversight across all companies, client sites, and user credentials.
              </p>
            </div>
            <button 
              type="button" 
              className="skeuo-btn-primary" 
              onClick={() => setShowNewTenantModal(true)}
            >
              + Assign ChatBox to Company
            </button>
          </div>

          {/* Metric KPI Cards */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '16px', marginBottom: '26px' }}>
            <div className="skeuo-card" style={{ padding: '20px' }}>
              <div style={{ fontSize: '12px', fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>Total Companies</div>
              <div style={{ fontSize: '28px', fontWeight: 800, color: '#2563eb', marginTop: '6px' }}>{platformStats.tenants}</div>
            </div>
            <div className="skeuo-card" style={{ padding: '20px' }}>
              <div style={{ fontSize: '12px', fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>Active Websites</div>
              <div style={{ fontSize: '28px', fontWeight: 800, color: '#10b981', marginTop: '6px' }}>{platformStats.properties}</div>
            </div>
            <div className="skeuo-card" style={{ padding: '20px' }}>
              <div style={{ fontSize: '12px', fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>Global Agents</div>
              <div style={{ fontSize: '28px', fontWeight: 800, color: '#f97316', marginTop: '6px' }}>{platformStats.agents}</div>
            </div>
            <div className="skeuo-card" style={{ padding: '20px' }}>
              <div style={{ fontSize: '12px', fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>Processed Messages</div>
              <div style={{ fontSize: '28px', fontWeight: 800, color: '#8b5cf6', marginTop: '6px' }}>{platformStats.messages}</div>
            </div>
          </div>

          {/* Platform Users & Access Control Table */}
          <div className="skeuo-card" style={{ padding: '20px', marginBottom: '26px' }}>
            <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#0f172a', marginBottom: '14px' }}>
              👥 Platform Users & Access Control (Edit Any User)
            </h3>
            <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
              <thead>
                <tr style={{ background: '#f1f5f9', borderBottom: '1px solid rgba(203,213,225,0.7)' }}>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Name</th>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Email</th>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Role</th>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Tenant</th>
                  <th style={{ padding: '10px 14px', color: '#475569', textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {platformUsers.map(u => (
                  <tr key={u.id} style={{ borderBottom: '1px solid rgba(203,213,225,0.4)' }}>
                    <td style={{ padding: '12px 14px', fontWeight: 700, color: '#0f172a' }}>{u.name}</td>
                    <td style={{ padding: '12px 14px', color: '#475569', fontFamily: 'monospace' }}>{u.email}</td>
                    <td style={{ padding: '12px 14px' }}>
                      <span className={`skeuo-badge ${u.role === 'super_admin' ? 'skeuo-badge-orange' : (u.role === 'admin' ? 'skeuo-badge-blue' : 'skeuo-badge-green')}`}>
                        {u.role}
                      </span>
                    </td>
                    <td style={{ padding: '12px 14px', color: '#64748b' }}>
                      {u.tenant?.name || 'Platform Master'}
                    </td>
                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>
                      <button 
                        type="button" 
                        className="skeuo-btn" 
                        style={{ padding: '5px 12px', fontSize: '12px' }}
                        onClick={() => {
                          setEditingUser({ ...u, password: '' });
                          setShowEditUserModal(true);
                        }}
                      >
                        ✏️ Edit Credentials
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Companies Directory Table */}
          <div className="skeuo-card" style={{ padding: '20px' }}>
            <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#0f172a', marginBottom: '14px' }}>
              🏢 Registered Companies (Tenants)
            </h3>
            <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
              <thead>
                <tr style={{ background: '#f1f5f9', borderBottom: '1px solid rgba(203,213,225,0.7)' }}>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Company</th>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Owner Email</th>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Plan</th>
                  <th style={{ padding: '10px 14px', color: '#475569' }}>Status</th>
                </tr>
              </thead>
              <tbody>
                {platformTenants.map(t => (
                  <tr key={t.id} style={{ borderBottom: '1px solid rgba(203,213,225,0.4)' }}>
                    <td style={{ padding: '12px 14px', fontWeight: 700, color: '#0f172a' }}>{t.name}</td>
                    <td style={{ padding: '12px 14px', color: '#475569' }}>{t.email}</td>
                    <td style={{ padding: '12px 14px' }}><span className="skeuo-badge skeuo-badge-blue">{t.plan}</span></td>
                    <td style={{ padding: '12px 14px' }}><span className="skeuo-badge skeuo-badge-green">{t.status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Modal: Assign ChatBox to Company */}
          {showNewTenantModal && (
            <div style={{
              position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)', backdropFilter: 'blur(4px)',
              display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '20px'
            }}>
              <div className="skeuo-card" style={{ width: '100%', maxWidth: '520px', padding: '28px', maxHeight: '90vh', overflowY: 'auto' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
                  <h3 style={{ fontSize: '18px', fontWeight: 800, color: '#0f172a' }}>
                    + Assign ChatBox to Company
                  </h3>
                  <button onClick={() => setShowNewTenantModal(false)} style={{ background: 'none', border: 'none', fontSize: '18px', cursor: 'pointer', color: '#64748b' }}>✕</button>
                </div>

                <form onSubmit={handleCreateTenant}>
                  <div style={{ marginBottom: '14px' }}>
                    <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Company Name</label>
                    <input type="text" className="skeuo-input" required placeholder="Acme Corporation" value={newCompany.name} onChange={e => setNewCompany({ ...newCompany, name: e.target.value })} />
                  </div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', marginBottom: '14px' }}>
                    <div>
                      <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Admin Name</label>
                      <input type="text" className="skeuo-input" required placeholder="John Doe" value={newCompany.adminName} onChange={e => setNewCompany({ ...newCompany, adminName: e.target.value })} />
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Admin Email</label>
                      <input type="email" className="skeuo-input" required placeholder="admin@acme.com" value={newCompany.adminEmail} onChange={e => setNewCompany({ ...newCompany, adminEmail: e.target.value })} />
                    </div>
                  </div>
                  <div style={{ marginBottom: '14px' }}>
                    <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Admin Password</label>
                    <input type="password" className="skeuo-input" required placeholder="••••••••" value={newCompany.adminPass} onChange={e => setNewCompany({ ...newCompany, adminPass: e.target.value })} />
                  </div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', marginBottom: '14px' }}>
                    <div>
                      <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Website Name</label>
                      <input type="text" className="skeuo-input" required placeholder="Acme Official" value={newCompany.siteName} onChange={e => setNewCompany({ ...newCompany, siteName: e.target.value })} />
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Website URL</label>
                      <input type="url" className="skeuo-input" required placeholder="https://acme.com" value={newCompany.siteUrl} onChange={e => setNewCompany({ ...newCompany, siteUrl: e.target.value })} />
                    </div>
                  </div>
                  <div style={{ marginBottom: '22px' }}>
                    <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Subscription Plan</label>
                    <select className="skeuo-input" value={newCompany.plan} onChange={e => setNewCompany({ ...newCompany, plan: e.target.value })}>
                      <option value="pro">Pro ($49/mo)</option>
                      <option value="enterprise">Enterprise ($149/mo)</option>
                    </select>
                  </div>
                  <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                    <button type="button" className="skeuo-btn" onClick={() => setShowNewTenantModal(false)}>Cancel</button>
                    <button type="submit" className="skeuo-btn-primary">🚀 Create & Assign ChatBox</button>
                  </div>
                </form>
              </div>
            </div>
          )}

          {/* Modal: Edit User Credentials (Super Admin) */}
          {showEditUserModal && editingUser && (
            <div style={{
              position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)', backdropFilter: 'blur(4px)',
              display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '20px'
            }}>
              <div className="skeuo-card" style={{ width: '100%', maxWidth: '440px', padding: '28px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
                  <h3 style={{ fontSize: '17px', fontWeight: 700, color: '#0f172a' }}>✏️ Edit User Credentials</h3>
                  <button onClick={() => setShowEditUserModal(false)} style={{ background: 'none', border: 'none', fontSize: '18px', cursor: 'pointer', color: '#64748b' }}>✕</button>
                </div>
                <div style={{ marginBottom: '14px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Name</label>
                  <input type="text" className="skeuo-input" value={editingUser.name} onChange={e => setEditingUser({ ...editingUser, name: e.target.value })} />
                </div>
                <div style={{ marginBottom: '14px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Email</label>
                  <input type="email" className="skeuo-input" value={editingUser.email} onChange={e => setEditingUser({ ...editingUser, email: e.target.value })} />
                </div>
                <div style={{ marginBottom: '14px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>Role</label>
                  <select className="skeuo-input" value={editingUser.role} onChange={e => setEditingUser({ ...editingUser, role: e.target.value })}>
                    <option value="super_admin">⚡ Super Admin</option>
                    <option value="admin">👑 Company Admin</option>
                    <option value="agent">🎧 Support Agent</option>
                  </select>
                </div>
                <div style={{ marginBottom: '22px' }}>
                  <label style={{ display: 'block', fontSize: '12.5px', fontWeight: 600, color: '#334155', marginBottom: '5px' }}>New Password (leave blank to keep current)</label>
                  <input type="password" className="skeuo-input" placeholder="••••••••" value={editingUser.password} onChange={e => setEditingUser({ ...editingUser, password: e.target.value })} />
                </div>
                <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                  <button type="button" className="skeuo-btn" onClick={() => setShowEditUserModal(false)}>Cancel</button>
                  <button type="button" className="skeuo-btn-primary" onClick={handleUpdateUser}>Save Credentials</button>
                </div>
              </div>
            </div>
          )}

        </main>
      )}

      {/* ====================================================================
          VIEW 4: 🎨 WIDGET CUSTOMIZER & 1-LINE EMBED CODE
          ==================================================================== */}
      {activeView === 'customizer' && (
        <main style={{ flex: 1, padding: '28px', overflowY: 'auto' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '22px' }}>
            <div>
              <h2 style={{ fontSize: '24px', fontWeight: 800, color: '#0f172a' }}>🎨 Widget & 1-Line Embed Script</h2>
              <p style={{ fontSize: '13px', color: '#64748b' }}>Copy and paste this 1-line script onto any website to activate live chat.</p>
            </div>
            <button type="button" className="skeuo-btn-primary" onClick={() => setShowEmbedModal(true)}>
              📖 How to Add to Any Website
            </button>
          </div>

          <div className="skeuo-card" style={{ padding: '24px', marginBottom: '24px' }}>
            <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#0f172a', marginBottom: '12px' }}>1-Line Embed Code</h3>
            <p style={{ fontSize: '13px', color: '#64748b', marginBottom: '14px' }}>
              Add this snippet right before the closing <code>&lt;/body&gt;</code> tag of your website:
            </p>
            <div className="skeuo-inset" style={{ padding: '16px', fontFamily: 'monospace', fontSize: '13px', color: '#1e293b', whiteSpace: 'pre-wrap', marginBottom: '16px' }}>
              {embedSnippet}
            </div>
            <button 
              type="button" 
              className="skeuo-btn-primary" 
              onClick={() => {
                navigator.clipboard.writeText(embedSnippet);
                setCopiedSnippet(true);
                setTimeout(() => setCopiedSnippet(false), 2000);
              }}
            >
              {copiedSnippet ? '✓ Copied to Clipboard!' : '📋 Copy Embed Code'}
            </button>
          </div>
        </main>
      )}

      {/* Embed Guide Modal */}
      {showEmbedModal && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.45)', backdropFilter: 'blur(4px)',
          display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '20px'
        }}>
          <div className="skeuo-card" style={{ width: '100%', maxWidth: '600px', padding: '28px', maxHeight: '85vh', overflowY: 'auto' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px' }}>
              <h3 style={{ fontSize: '18px', fontWeight: 800, color: '#0f172a' }}>📖 How to Add ChatBox to Websites</h3>
              <button onClick={() => setShowEmbedModal(false)} style={{ background: 'none', border: 'none', fontSize: '18px', cursor: 'pointer', color: '#64748b' }}>✕</button>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '16px', fontSize: '13.5px', color: '#334155', lineHeight: 1.6 }}>
              <div>
                <strong>1. Plain HTML / PHP:</strong>
                <p>Paste the 1-line script right before the closing <code>&lt;/body&gt;</code> tag in <code>index.html</code> or <code>footer.php</code>.</p>
              </div>
              <div>
                <strong>2. WordPress:</strong>
                <p>Install the <strong>WPCode</strong> plugin &gt; Go to <strong>Header &amp; Footer</strong> &gt; Paste the script into the <strong>Footer</strong> section &gt; Click <strong>Save Changes</strong>.</p>
              </div>
              <div>
                <strong>3. Shopify:</strong>
                <p>Go to <strong>Online Store &gt; Themes &gt; Edit Code</strong> &gt; Open <code>theme.liquid</code> &gt; Scroll to the bottom and paste right before <code>&lt;/body&gt;</code> &gt; Save.</p>
              </div>
              <div>
                <strong>4. Next.js / React:</strong>
                <p>In <code>app/layout.jsx</code>, use <code>&lt;Script src=".../widget.js" data-property-key="pk_..." strategy="lazyOnload" /&gt;</code>.</p>
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
}
