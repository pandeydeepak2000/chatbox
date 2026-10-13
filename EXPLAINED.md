# ChatBox — Enterprise Live Chat & CRM Platform: सम्पूर्ण कार्यप्रणाली व टेस्टिंग गाइड

इस गाइड में आपके पूछे गए सभी प्रश्नों के स्पष्ट उत्तर, नया **Next.js Skeuomorphic डैशबोर्ड**, **CRM Leads ट्रैकर**, रियल-टाइम नोटिफ़िकेशन और किसी भी वेबसाइट में जोड़ने का तरीका विस्तार से समझाया गया है।

---

## ❓ 1. जब क्लाइंट चैट करता है तो कहाँ दिखता है और कैसे पता चलता है कि कोई ऑनलाइन है या मैसेज आया है?

जब भी कोई विज़िटर किसी क्लाइंट वेबसाइट या डेमो पेज (`http://localhost:8000/demo`) पर जाकर चैट शुरू करता है या मैसेज भेजता है:

### एजेंट / एडमिन को कैसे पता चलता है (Real-time Signals):
1. 🔔 **सुरीली ऑडियो घंटी (Audio Chime Ping)**:
   - जैसे ही मैसेज आता है, ब्राउज़र में तुरंत एक साफ़ और मधुर "Ding!" बीप बजती है, जिससे स्क्रीन न देख रहे एजेंट को भी तुरंत आवाज़ सुनाई दे जाती है।
2. 🟢 **लाइव ऑनलाइन इंडिकेटर (Pulsing Green LED)**:
   - विज़िटर के कार्ड और टेलीमेट्री पैनल में हरा चमकता हुआ बिंदु (`● Online Now`) ब्लिंक करता है।
3. 💬 **फ़्लोटिंग टोस्ट नोटिफ़िकेशन (Top-Right Alert)**:
   - डैशबोर्ड पर ऊपर दाईं ओर एक 3D कार्ड पॉपअप आता है: `"🔔 New Chat: [Visitor Name] sent a message!"`
4. 📬 **Unassigned फ़ीड में ऊपर आना**:
   - बातचीत तुरंत **"Unassigned"** फ़ीड में सबसे ऊपर आ जाती है और उस पर ऑरेंज रंग का न्यू मैसेज बैज दिखता है।
5. 🔍 **विज़िटर टेलीमेट्री (Visitor Intel Panel)**:
   - चैट पर क्लिक करते ही राइट साइडबार में दिखता है:
     - विज़िटर का नाम और ईमेल
     - लाइव लोकेशन (City, Country)
     - IP Address
     - Browser & OS (उदा. Chrome on Windows)
     - वह वेबसाइट के किस पेज पर है (Current Page URL)

---

## 🎯 2. CRM Leads Tracking — फॉर्म सबमिट करने वाले विज़िटर्स का डेटा लीड के तौर पर कैसे रहेगा?

हमने एक समर्पित **🎯 CRM Leads Hub** तैयार किया है:

### यह कैसे काम करता है:
1. जब भी कोई विज़िटर चैट विजेट में अपना नाम, ईमेल, फ़ोन नंबर और पहला सवाल भरता है, बैकएंड उसे सिर्फ एक चैट नहीं बल्कि एक **Qualified CRM Lead** के रूप में हमेशा के लिए सेव कर लेता है।
2. डैशबोर्ड में बाएं मेनू से **"🎯 CRM Leads Hub"** पर क्लिक करें।
3. वहाँ आपको एक पूरी CRM टेबल दिखेगी जिसमें:
   - **Lead Name**: विज़िटर का नाम
   - **Contact Details**: ईमेल और फ़ोन नंबर
   - **Initial Inquiry**: विज़िटर का पहला सवाल/ज़रूरत
   - **Location & Page**: वह किस शहर/पेज से आया
   - **Lead Lifecycle Status**:
     - 🟡 `New` (नई लीड)
     - 🔵 `Contacted` (एजेंट ने संपर्क किया)
     - 🟣 `Qualified` (कस्टमर इच्छुक है)
     - 🟢 `Converted` (डील पक्की / ग्राहक बन गया)
     - ⚪ `Lost` (क्लोज्ड)
4. **✏️ Update Status Modal**:
   - एजेंट किसी भी लीड के सामने "Update Status" दबाकर स्टेटस बदल सकते हैं और अंदरूनी फॉलो-अप नोट्स (Internal CRM Notes) लिख सकते हैं।
5. **📥 1-Click CSV Export**:
   - "Export CSV" बटन दबाकर सभी लीड्स को एक्सेल या गूगल शीट्स में सीधे डाउनलोड किया जा सकता है।

---

## 🎨 3. Next.js और Skeuomorphism UI डिज़ाइन

यूज़र की पसंद के अनुसार हमने **Next.js** में एक शानदार **Skeuomorphic Design** बनाया है:
- **3D उभरे हुए बटन्स (Embossed Bevels)**: बटन दबाने पर वास्तविक बटन की तरह नीचे दबते हैं (`:active depth`)।
- **सॉफ्ट रियलिस्टिक शेडोज़ (Convex & Concave Surfaces)**: इनपुट और चैट एरिया अंदर धंसा हुआ (Inset Well) लगता है और कार्ड्स बाहर उभरे हुए दिखते हैं।
- **ग्लासी पिल बैजेस (Tactile Badges)**: स्टेटस बैजेस पर ऊपर से शीशे की चमक (Reflective Glaze) दिखाई देती है।
- **एलईडी सिग्नल्स (Glowing LEDs)**: ऑनलाइन विज़िटर के लिए असली एलईडी बल्ब जैसा इफ़ेक्ट।

### Next.js ऐप कैसे चलाएं:
```bash
cd c:\laravel\chatbox\frontend
npm run dev
```
👉 ब्राउज़र में खोलें: **`http://localhost:3000`**

*(Laravel का ओरिजिनल डैशबोर्ड भी `http://localhost:8000/dashboard` पर समानांतर रूप से चालू है)*

---

## 🌐 4. किसी भी वेबसाइट में ChatBox कैसे जोड़ें? (Embed Guide)

किसी भी वेबसाइट पर चैट विजेट चालू करने के लिए सिर्फ 1 लाइन का कोड `</body>` टैग से ठीक पहले लगाना होता है:

### 1-Line Embed Code:
```html
<!-- ChatBox Live Support Widget -->
<script 
  src="http://localhost:8000/widget.js" 
  data-property-key="pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD" 
  async>
</script>
```

### विभिन्न प्लेटफ़ॉर्म्स पर लगाने के स्टेप्स:
1. **Custom HTML / PHP वेबसाइट**:
   - `index.html` या `footer.php` खोलें और `</body>` से ठीक ऊपर यह कोड पेस्ट करके सेव करें।
2. **WordPress**:
   - WordPress एडमिन में जाएं -> **Plugins > Add New** -> **"WPCode"** या **"Insert Headers and Footers"** प्लगइन लगाएं।
   - प्लगइन की सेटिंग्स में जाकर **Footer** वाले बॉक्स में यह कोड पेस्ट करके सेव कर दें।
3. **Shopify**:
   - Shopify एडमिन -> **Online Store > Themes > Actions > Edit Code**।
   - `theme.liquid` फ़ाइल खोलें, नीचे स्क्रॉल करके `</body>` से पहले कोड पेस्ट करें और **Save** दबाएं।
4. **Next.js / React**:
   - `app/layout.jsx` में:
     ```jsx
     import Script from 'next/script';

     <Script 
       src="http://localhost:8000/widget.js" 
       data-property-key="pk_live_chatbox_FiTEeVrRTkfRAuGWIhjD" 
       strategy="lazyOnload" 
     />
     ```

---

## ⚡ 5. सुपर एडमिन द्वारा कंपनी ऑनबोर्डिंग और क्रेडेंशियल्स अपडेट

- **Super Admin Login**: `ambitionmanagements@gmail.com` / `password123`
- **"+ Assign ChatBox to Company"**: नई कंपनी का नाम, एडमिन ईमेल, पासवर्ड और वेबसाइट डालें — सिस्टम तुरंत उनकी कंपनी का वर्कस्पेस, एडमिन अकाउंट, और यूनिक पब्लिक की (`pk_live_...`) जेनरेट कर देता है।
- **"Platform Users & Access Control"**: किसी भी यूज़र का नाम, ईमेल, रोल (`super_admin`, `admin`, `agent`), या नया पासवर्ड एक क्लिक में अपडेट करें।
