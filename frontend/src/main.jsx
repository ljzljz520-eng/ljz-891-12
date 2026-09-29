import React from 'react'
import ReactDOM from 'react-dom/client'
import axios from 'axios'
import App from './App.jsx'
import './index.css'

// Global response interceptor: never let a non-JSON / network error white-screen the UI.
axios.interceptors.response.use(
  (response) => {
    // If the backend returns HTML (e.g. PHP fatal error page), treat it as an error.
    const contentType = response.headers?.['content-type'] || '';
    if (typeof response.data === 'string' && contentType.includes('text/html')) {
      return Promise.reject({
        response: { status: response.status, data: { message: '服务暂时不可用，请稍后重试' } }
      });
    }
    return response;
  },
  (error) => {
    if (!error.response) {
      // Network error / backend unreachable
      error.message = '网络连接异常，请检查网络后重试';
    } else if (typeof error.response.data === 'string') {
      // Backend returned a non-JSON error page
      error.response.data = { message: '服务暂时不可用，请稍后重试' };
    }
    return Promise.reject(error);
  }
);

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
