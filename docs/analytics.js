/**
 * Ozone Analytics Tracking Pixel (v1.8)
 * ระบบเก็บข้อมูลสถิติที่เชื่อมต่อกับ Firebase Firestore โดยตรง
 */
import { initializeApp } from "https://www.gstatic.com/firebasejs/11.6.1/firebase-app.js";
import { getFirestore, collection, addDoc, serverTimestamp } from "https://www.gstatic.com/firebasejs/11.6.1/firebase-firestore.js";
import { getAuth, signInAnonymously, signInWithCustomToken, onAuthStateChanged } from "https://www.gstatic.com/firebasejs/11.6.1/firebase-auth.js";

async function initOzoneTracking() {
    // 1. ตรวจสอบ Config อย่างปลอดภัย (Mandatory Check)
    if (typeof __firebase_config === 'undefined' || !__firebase_config) {
        console.warn("Ozone Analytics: System environment not ready.");
        return;
    }

    try {
        const firebaseConfig = JSON.parse(__firebase_config);
        const appId = typeof __app_id !== 'undefined' ? __app_id : 'ozone-phrae';
        
        // 2. Initialize Firebase Services
        const app = initializeApp(firebaseConfig);
        const auth = getAuth(app);
        const db = getFirestore(app);

        // 3. ปฏิบัติตามกฎ Mandatory Rule 3: Auth ก่อนใช้งาน
        const initAuth = async () => {
            if (typeof __initial_auth_token !== 'undefined' && __initial_auth_token) {
                await signInWithCustomToken(auth, __initial_auth_token);
            } else {
                await signInAnonymously(auth);
            }
        };

        await initAuth();

        // 4. รอสถานะ Login สำเร็จก่อนส่งข้อมูล
        onAuthStateChanged(auth, async (user) => {
            if (!user) return;

            // ข้อมูลที่ต้องการเก็บ
            const trackingData = {
                page_title: document.title,
                page_path: window.location.pathname,
                referrer: document.referrer || 'Direct',
                device_type: window.innerWidth < 768 ? 'Mobile' : 'Desktop',
                timestamp: serverTimestamp(),
                user_id: user.uid
            };

            try {
                // 5. ปฏิบัติตามกฎ Mandatory Rule 1: Path ต้องเป็น /artifacts/{appId}/public/data/tracking
                const trackingCol = collection(db, 'artifacts', appId, 'public', 'data', 'tracking');
                
                await addDoc(trackingCol, trackingData);
                console.log("Ozone Analytics: [Success] Data sent to cloud.");
            } catch (err) {
                console.error("Ozone Analytics: [Error] Failed to sync data", err);
            }
        });

    } catch (error) {
        console.error("Ozone Analytics: [Error] Initialization failed", error);
    }
}

// เริ่มทำงานเมื่อหน้าเว็บพร้อม
if (document.readyState === 'complete') {
    initOzoneTracking();
} else {
    window.addEventListener('load', initOzoneTracking);
}