import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'react-hot-toast';
import { Mail, RefreshCw, Key, ShieldCheck, ArrowLeft, AlertTriangle, Clock } from 'lucide-react';

export default function UpdatePage() {
  const [step, setStep] = useState(1); // 1: verify identity, 2: modify info
  const [qq, setQq] = useState('');
  const [product, setProduct] = useState('');
  const [code, setCode] = useState('');
  const [newOwner, setNewOwner] = useState('');
  const [newEmail, setNewEmail] = useState('');
  const [cooldown, setCooldown] = useState(0);
  const [sending, setSending] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [boundEmail, setBoundEmail] = useState('');
  const [updatedInfo, setUpdatedInfo] = useState(null);

  // Cooldown ticker
  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = setInterval(() => setCooldown(c => Math.max(0, c - 1)), 1000);
    return () => clearInterval(timer);
  }, [cooldown]);

  const handleSendCode = async () => {
    if (!qq.trim()) { toast.error('请输入授权QQ'); return; }
    if (!product.trim()) { toast.error('请输入所属产品'); return; }
    if (cooldown > 0 || sending) return;

    setSending(true);
    try {
      const res = await axios.post('/api/license/send-code', {
        qq: qq.trim(),
        product_name: product.trim()
      });

      if (res.data.status === 'error') {
        // Email sending failed (or other business error) - show prompt, never white-screen
        toast.error(res.data.message || '验证码发送失败，请稍后重试');
        if (res.data.mock_code) {
          // Demo fallback: SMTP unavailable, code returned for testing only
          toast(`测试环境验证码: ${res.data.mock_code}`, { icon: '🔍', duration: 8000 });
        }
        if (res.data.retry_after) setCooldown(res.data.retry_after);
        return;
      }

      toast.success(res.data.message || '验证码已发送');
      setBoundEmail(res.data.email || `${qq.trim()}@qq.com`);
      setCooldown(60);
      setStep(2);
    } catch (err) {
      const data = err.response?.data;
      if (data?.message) {
        toast.error(data.message);
        if (data.retry_after) setCooldown(data.retry_after);
      } else {
        toast.error('验证码发送失败，请稍后重试');
      }
    } finally {
      setSending(false);
    }
  };

  const handleUpdate = async (e) => {
    e.preventDefault();
    if (!code.trim()) { toast.error('请输入邮箱验证码'); return; }
    if (!newOwner.trim() && !newEmail.trim()) {
      toast.error('请填写需要修改的内容（授权主人或联系邮箱）');
      return;
    }
    if (newEmail.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(newEmail.trim())) {
      toast.error('联系邮箱格式不正确');
      return;
    }

    setSubmitting(true);
    try {
      const res = await axios.post('/api/license/update', {
        qq: qq.trim(),
        product_name: product.trim(),
        code: code.trim(),
        new_owner: newOwner.trim(),
        new_email: newEmail.trim()
      });

      if (res.data.status === 'error') {
        toast.error(res.data.message || '修改失败，请稍后重试');
        return;
      }

      toast.success('授权资料修改成功');
      setUpdatedInfo(res.data.data);
    } catch (err) {
      const data = err.response?.data;
      toast.error(data?.message || '修改失败，请稍后重试');
    } finally {
      setSubmitting(false);
    }
  };

  const resetFlow = () => {
    setStep(1);
    setCode('');
    setNewOwner('');
    setNewEmail('');
    setUpdatedInfo(null);
    setBoundEmail('');
  };

  return (
    <div className="max-w-xl mx-auto mt-10">
      <div className="glass-card p-8">
        <h2 className="text-2xl font-bold mb-2 text-center">自助更绑</h2>
        <p className="text-white/50 text-sm text-center mb-6">
          输入授权QQ与所属产品，验证码将发送至绑定QQ邮箱，验证通过后可修改授权资料
        </p>

        {/* Step indicator */}
        <div className="flex items-center justify-center gap-2 mb-8 text-sm">
          <StepDot active={step === 1} done={step === 2} label="1. 验证身份" />
          <div className="w-8 h-px bg-white/20" />
          <StepDot active={step === 2} done={!!updatedInfo} label="2. 修改资料" />
        </div>

        {updatedInfo ? (
          <div className="space-y-5">
            <div className="bg-green-500/10 border border-green-500/30 rounded-xl p-5 flex items-start gap-3">
              <ShieldCheck className="text-green-400 w-6 h-6 shrink-0 mt-0.5" />
              <div>
                <p className="text-green-300 font-semibold">授权资料修改成功</p>
                <p className="text-white/60 text-sm mt-1">新资料已生效，请妥善保管。</p>
              </div>
            </div>
            <div className="bg-white/5 rounded-xl p-5 space-y-2 text-sm">
              <InfoRow label="授权QQ" value={updatedInfo.qq} />
              <InfoRow label="授权主人" value={updatedInfo.owner_name} />
              <InfoRow label="所属产品" value={updatedInfo.product_name} />
              <InfoRow label="联系邮箱" value={updatedInfo.contact_email || `${updatedInfo.qq}@qq.com（绑定邮箱）`} />
            </div>
            <button onClick={resetFlow} className="tech-button w-full">继续办理其他授权</button>
          </div>
        ) : step === 1 ? (
          <form onSubmit={(e) => { e.preventDefault(); handleSendCode(); }} className="space-y-5">
            <div>
              <label className="block text-sm mb-2 text-white/70">授权QQ</label>
              <input
                type="text"
                value={qq}
                onChange={e => setQq(e.target.value)}
                className="glass-input w-full"
                placeholder="请输入授权QQ号码"
              />
            </div>
            <div>
              <label className="block text-sm mb-2 text-white/70">所属产品</label>
              <input
                type="text"
                value={product}
                onChange={e => setProduct(e.target.value)}
                className="glass-input w-full"
                placeholder="请输入授权的产品名称"
              />
            </div>
            <button
              type="submit"
              disabled={sending || cooldown > 0}
              className="tech-button w-full disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              {sending ? (
                <><div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" /> 正在发送...</>
              ) : cooldown > 0 ? (
                <><Clock size={16} /> {cooldown}s 后可重新发送</>
              ) : (
                <><Mail size={16} /> 发送验证码至绑定邮箱</>
              )}
            </button>
            <p className="text-white/40 text-xs text-center flex items-center justify-center gap-1">
              <AlertTriangle size={12} /> 验证码10分钟内有效，频繁发送将被限制
            </p>
          </form>
        ) : (
          <form onSubmit={handleUpdate} className="space-y-5">
            <div className="bg-sky-500/10 border border-sky-500/30 rounded-lg px-4 py-3 text-sm text-sky-200 flex items-center gap-2">
              <Mail size={15} className="shrink-0" />
              验证码已发送至绑定邮箱 <span className="font-mono">{boundEmail}</span>
            </div>

            <div>
              <label className="block text-sm mb-2 text-white/70">邮箱验证码</label>
              <div className="flex gap-2">
                <div className="relative flex-1">
                  <Key className="absolute left-3 top-2.5 w-4 h-4 text-white/40" />
                  <input
                    type="text"
                    value={code}
                    onChange={e => setCode(e.target.value)}
                    className="glass-input w-full pl-10"
                    placeholder="收到的6位验证码"
                    maxLength={6}
                  />
                </div>
                <button
                  type="button"
                  onClick={handleSendCode}
                  disabled={cooldown > 0 || sending}
                  className={`px-4 py-2 rounded-lg font-medium text-sm transition whitespace-nowrap ${
                    cooldown > 0
                      ? 'bg-white/10 text-white/40 cursor-not-allowed'
                      : 'bg-sky-500 hover:bg-sky-400 text-white'
                  }`}
                >
                  {sending ? '发送中...' : cooldown > 0 ? `${cooldown}s 后重发` : '重新发送'}
                </button>
              </div>
            </div>

            <div>
              <label className="block text-sm mb-2 text-white/70">
                新授权主人名称 <span className="text-white/30">（不修改请留空）</span>
              </label>
              <div className="relative">
                <RefreshCw className="absolute left-3 top-2.5 w-4 h-4 text-white/40" />
                <input
                  type="text"
                  value={newOwner}
                  onChange={e => setNewOwner(e.target.value)}
                  className="glass-input w-full pl-10"
                  placeholder="请输入新的主人名称"
                />
              </div>
            </div>

            <div>
              <label className="block text-sm mb-2 text-white/70">
                新联系邮箱 <span className="text-white/30">（不修改请留空，默认使用QQ邮箱）</span>
              </label>
              <div className="relative">
                <Mail className="absolute left-3 top-2.5 w-4 h-4 text-white/40" />
                <input
                  type="email"
                  value={newEmail}
                  onChange={e => setNewEmail(e.target.value)}
                  className="glass-input w-full pl-10"
                  placeholder="example@domain.com"
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={submitting}
              className="tech-button w-full disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              {submitting ? (
                <><div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" /> 正在提交...</>
              ) : '确认修改'}
            </button>
            <button
              type="button"
              onClick={resetFlow}
              className="w-full text-white/40 hover:text-white/70 text-sm flex items-center justify-center gap-1 transition"
            >
              <ArrowLeft size={13} /> 重新输入授权信息
            </button>
          </form>
        )}
      </div>
    </div>
  );
}

function StepDot({ active, done, label }) {
  return (
    <div className={`flex items-center gap-2 text-sm ${active ? 'text-sky-300' : done ? 'text-green-400' : 'text-white/40'}`}>
      <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold border ${
        active ? 'border-sky-400 bg-sky-500/20' : done ? 'border-green-400 bg-green-500/20' : 'border-white/20'
      }`}>
        {done ? '✓' : active ? '●' : '○'}
      </div>
      {label}
    </div>
  );
}

function InfoRow({ label, value }) {
  return (
    <div className="flex justify-between items-center gap-4">
      <span className="text-white/50">{label}</span>
      <span className="text-sky-300 font-medium text-right">{value}</span>
    </div>
  );
}
