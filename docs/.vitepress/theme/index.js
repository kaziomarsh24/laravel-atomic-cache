import DefaultTheme from 'vitepress/theme';
import './custom.css';

export default {
  extends: DefaultTheme,
  enhanceApp({ app, router, siteData }) {
    if (typeof window !== 'undefined') {
      // Ensure clicking anywhere in the search bar container triggers search reliably
      document.addEventListener('click', (e) => {
        const searchBox = e.target.closest('#local-search');
        if (searchBox) {
          const btn = searchBox.querySelector('.DocSearch-Button');
          if (btn && e.target === searchBox) {
            btn.click();
          }
        }
      });
    }
  }
};
