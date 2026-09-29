import React from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

/**
 * 全局错误边界：捕获渲染期异常，展示可恢复的降级页而不是白屏。
 */
export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error, info) {
    console.error('页面渲染异常：', error, info);
  }

  handleReload = () => {
    this.setState({ hasError: false });
    window.location.reload();
  };

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-[#020617] p-6">
          <div className="max-w-md w-full glass-card p-10 text-center">
            <div className="w-16 h-16 bg-red-500/20 rounded-2xl flex items-center justify-center mx-auto mb-5 text-red-400">
              <AlertTriangle size={32} />
            </div>
            <h2 className="text-xl font-bold text-white mb-2">页面出了点问题</h2>
            <p className="text-white/50 text-sm leading-relaxed mb-8">
              系统在处理您的操作时发生异常，您的授权资料未受影响。请刷新页面重试，若问题持续出现请联系管理员。
            </p>
            <button
              onClick={this.handleReload}
              className="tech-button w-full flex items-center justify-center gap-2"
            >
              <RefreshCw size={15} /> 刷新页面
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
