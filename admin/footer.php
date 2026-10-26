 </main>
 <!-- Footer -->
 <footer class="admin-footer">
 <div class="footer-content">
 <p class="footer-text">
 &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($adminBrandName ?? 'Cloud Aspire'); ?> Admin Panel. All rights reserved.
 </p>
 </div>
 </footer>

 <style>
 /* Footer */
 .admin-footer {
 background: var(--surface);
 border-top: 1px solid var(--border);
 padding: 20px 32px;
 margin-top: auto;
 width: 100%;
 box-sizing: border-box;
 transition: all var(--transition-smooth);
 }

 .footer-content {
 max-width: 1440px;
 width: 100%;
 margin: 0 auto;
 display: flex;
 justify-content: center;
 align-items: center;
 text-align: center;
 box-sizing: border-box;
 }

 .footer-text {
 margin: 0;
 font-size: 14px;
 color: var(--text-tertiary);
 }

 @media (max-width: 960px) {
 .admin-footer {
 padding: 16px 16px;
 }
 }

 @media (max-width: 480px) {
 .admin-footer {
 padding: 14px 12px;
 }
 }
 </style>

 </body>
 </html>
