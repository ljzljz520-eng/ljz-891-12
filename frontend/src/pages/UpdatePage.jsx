import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { toast } from 'react-hot-toast';
import {
  Mail, User, KeyRound, Send, ShieldCheck, ArrowRight, ArrowLeft,
  RefreshCw, AlertCircle, Loader2, CheckCircle2, Inbox, Info
} from 'lucide-react';

export default function UpdatePage() {
  // step: 1=填QQ+产品  2=输验证码  3=改资料
  const [step, setStep] = useState(1);

  const [qq, setQq] = useState('');
  const [product, setProduct] = useState('');
  const [code, setCode] = useState('');
  const [newOwner, setNewOwner] = useState('');
  const [newEmail, setNewEmail] = useState('');

  const [maskedEmail, setMaskedEmail] = useState('');
  const [cooldown, setCooldown] = useState(0);
  const [sending, setSending] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [sendError, setSendError] = useState('');
  const [result, setResult] = useState(null);

  const timerRef = useRef(null);

  useEffect(() => () => {
    if (timerRef.current) clearInterval(timerRef.current);
  }, []);

  const startCooldown = (seconds = 60) => {
    setCooldown(seconds);
    if (timerRef.current) clearInterval(timerRef.current);
    timerRef.current = setInterval(() => {
      setCooldown(prev => {
        if (prev <= 1) {
          clearInterval(timerRef.current);
          return 0;
        }
        return prev - 1;
      });
    }, 1000);
  };

  /* ------------------------------ Step 1 发送 ------------------------------ */
  const sendCode = async () => {
    setSendError('');

    if (!/^[1-9]\d{4,11}$/.test(qq.trim())) {
      toast.error('请输入正确的授权QQ号（5-12位数字）');
      return;
    }
    if (!product.trim()) {
      toast.error('请输入所属产品名称');
      return;
    }

    setSending(true);
    try {
      const res = await axios.post('/api/license/send-code', {
        qq: qq.trim(),
        product_name: product.trim(),
      });

      setMaskedEmail(res.data.masked_email || `${qq.slice(0, 2)}***@qq.com`);
      toast.success(res.data.message || '验证码已发送');

      if (res.data.mock && res.data.mock_code) {
        // 模拟模式下展示验证码，方便联调
        toast(`模拟模式验证码：${res.data.mock_code}`, { icon: '🔍', duration: 8000 });
      }

      startCooldown(60);
      setStep(2);
    } catch (err) {
      const status = err?.response?.status;
      const data = err?.response?.data || {};

      if (status === 429 && data.error === 'cooldown') {
        const wait = data.retry_after || 60;
        toast.error(data.message);
        startCooldown(wait);
      } else if (status === 429) {
        toast.error(data.message || '发送过于频繁，请稍后再试');
      } else if (status === 502) {
        // 邮件发送失败：页面内提示，不白屏、可重试
        setSendError(data.message || '验证码邮件发送失败，请稍后重试');
        toast.error('邮件发送失败');
      } else if (status === 404) {
        toast.error(data.message || '未找到对应的授权信息');
      } else {
        setSendError('网络异常，验证码发送失败，请检查网络后重试');
        toast.error('发送失败，请稍后重试');
      }
    } finally {
      setSending(false);
    }
  };

  /* ------------------------------ Step 2 验证 ------------------------------ */
  const handleVerify = async (e) => {
    e?.preventDefault();
    setSendError('');

    if (!/^\d{6}$/.test(code.trim())) {
      toast.error('请输入邮箱收到的6位数字验证码');
      return;
    }

    setVerifying(true);
    try {
      const res = await axios.post('/api/license/verify-code', {
        qq: qq.trim(),
        product_name: product.trim(),
        code: code.trim(),
      });

      setNewOwner(res.data.data?.owner || '');
      setNewEmail(res.data.data?.contact_email || '');
      toast.success('验证通过，请修改授权资料');
      setStep(3);
    } catch (err) {
      const data = err?.response?.data || {};
      const type = data.error;
      const message = data.message || '验证码校验失败';

      if (type === 'expired') {
        toast.error(message);
        setCode('');
        setCooldown(0); // 过期后允许立即重发
      } else if (type === 'too_many_attempts') {
        toast.error(message);
        setCode('');
        setCooldown(0);
      } else {
        toast.error(message, { duration: 4000 });
      }
    } finally {
      setVerifying(false);
    }
  };

  /* ------------------------------ Step 3 提交 ------------------------------ */
  const handleSubmit = async (e) => {
    e.preventDefault();
    setSendError('');

    const owner = newOwner.trim();
    const email = newEmail.trim();

    if (!owner && !email) {
      toast.error('授权主人和联系邮箱至少填写一项');
      return;
    }
    if (owner && owner.length > 50) {
      toast.error('授权主人名称最多50字');
      return;
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      toast.error('请输入正确的联系邮箱');
      return;
    }

    setSubmitting(true);
    try {
      const res = await axios.post('/api/license/update', {
        qq: qq.trim(),
        product_name: product.trim(),
        code: code.trim(),
        owner_name: owner,
        contact_email: email,
      });

      toast.success(res.data.message || '修改成功');

      // 成功后展示结果卡片
      setResult({
        owner: res.data.data?.owner ?? owner,
        email: res.data.data?.contact_email ?? email,
      });
      resetAll();
    } catch (err) {
      const data = err?.response?.data || {};
      const type = data.error;

      if (type === 'wrong_code') {
        toast.error(data.message || '验证码不正确');
        setStep(2);
      } else if (type === 'expired' || type === 'too_many_attempts') {
        toast.error(data.message || '验证码已失效，请重新获取');
        setCode('');
        setCooldown(0);
        setStep(2);
      } else if (type === 'invalid_email') {
        toast.error(data.message || '联系邮箱格式不正确');
      } else if (type === 'license_not_found') {
        toast.error(data.message || '授权信息不存在，请重新发起更绑');
        resetAll();
        setStep(1);
      } else {
        toast.error(data.message || '提交失败，请稍后重试');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const resetAll = () => {
    setStep(1);
    setQq('');
    setProduct('');
    setCode('');
    setNewOwner('');
    setNewEmail('');
    setMaskedEmail('');
    setCooldown(0);
    setSendError('');
  };

  const backToStep1 = () => {
    setStep(1);
    setCode('');
    setSendError('');
  };

  return (
    <div className="max-w-xl mx-auto mt-4">
      <div className="text-center mb-8">
        <h1 className="text-3xl font-extrabold bg-clip-text text-transparent bg-gradient-to-r from-sky-200 via-white to-sky-200 tracking-tight">
          自助修改授权资料
        </h1>
        <p className="text-white/40 mt-2 text-sm">
          输入授权 QQ 与所属产品，验证绑定 QQ 邮箱后即可修改授权主人 / 联系邮箱
        </p>
      </div>

      {/* 步骤指示器 */}
      <div className="flex items-center justify-center gap-2 mb-8 text-xs">
        {[
          { n: 1, label: '确认授权' },
          { n: 2, label: '邮箱验证' },
          { n: 3, label: '修改资料' },
        ].map((s, i) => (
          <React.Fragment key={s.n}>
            <div className={`flex items-center gap-2 px-3 py-1.5 rounded-full border transition-all ${
              step >= s.n
                ? 'bg-sky-500/20 border-sky-500/40 text-sky-300'
                : 'bg-white/5 border-white/10 text-white/30'
            }`}>
              <span className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold ${
                step > s.n ? 'bg-emerald-500 text-white' : step === s.n ? 'bg-sky-500 text-white' : 'bg-white/10'
              }`}>
                {step > s.n ? <CheckCircle2 size={12} /> : s.n}
              </span>
              {s.label}
            </div>
            {i < 2 && <div className={`w-6 h-px ${step > s.n ? 'bg-emerald-500/50' : 'bg-white/10'}`} />}
          </React.Fragment>
        ))}
      </div>

      <div className="glass-card p-8">
        {/* 发送失败等页面内错误提示（不白屏、可重试） */}
        {sendError && (
          <div className="mb-6 flex gap-3 bg-red-500/10 border border-red-500/30 rounded-xl p-4 animate-fade-in-up">
            <AlertCircle className="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
            <div className="text-sm">
              <p className="text-red-300 font-medium">{sendError}</p>
              <p className="text-red-200/50 text-xs mt-1">
                页面数据未丢失，您可以直接点击按钮重试；多次失败请联系管理员。
              </p>
            </div>
          </div>
        )}

        {/* ---------------- Step 1 ---------------- */}
        {step === 1 && (
          <form onSubmit={(e) => { e.preventDefault(); sendCode(); }} className="space-y-6">
            <div>
              <label className="block text-sm mb-2 text-white/70">授权 QQ</label>
              <div className="relative">
                <input
                  type="text"
                  inputMode="numeric"
                  maxLength={12}
                  value={qq}
                  onChange={e => setQq(e.target.value.replace(/\D/g, ''))}
                  className="glass-input w-full pl-10"
                  placeholder="请输入授权 QQ 号（5-12位数字）"
                />
                <User className="absolute left-3 top-2.5 w-4 h-4 text-white/40" />
              </div>
            </div>

            <div>
              <label className="block text-sm mb-2 text-white/70">所属产品</label>
              <div className="relative">
                <input
                  type="text"
                  value={product}
                  onChange={e => setProduct(e.target.value)}
                  className="glass-input w-full pl-10"
                  placeholder="请输入授权绑定的产品名称"
                />
                <ShieldCheck className="absolute left-3 top-2.5 w-4 h-4 text-white/40" />
              </div>
            </div>

            <div className="flex gap-2 text-xs text-white/40 bg-sky-500/5 border border-sky-500/20 rounded-lg p-3">
              <Info className="w-4 h-4 text-sky-400 shrink-0 mt-0.5" />
              <span>验证码将发送至该 QQ 的绑定邮箱 <b className="text-sky-300">QQ号@qq.com</b>，请注意查收（含垃圾邮件）。</span>
            </div>

            <button
              type="submit"
              disabled={sending || cooldown > 0}
              className="tech-button w-full flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed"
            >
              {sending ? (
                <><Loader2 className="w-4 h-4 animate-spin" /> 正在发送...</>
              ) : cooldown > 0 ? (
                <>重新发送（{cooldown}s）</>
              ) : (
                <>发送验证码 <Send size={15} /></>
              )}
            </button>
          </form>
        )}

        {/* ---------------- Step 2 ---------------- */}
        {step === 2 && (
          <form onSubmit={handleVerify} className="space-y-6">
            <div className="flex items-center gap-3 bg-white/5 rounded-xl p-4">
              <div className="p-2 bg-sky-500/20 rounded-lg text-sky-300">
                <Inbox size={20} />
              </div>
              <div className="text-sm">
                <p className="text-white/80">验证码已发送至</p>
                <p className="text-sky-300 font-mono font-bold">{maskedEmail || `${qq}@qq.com`}</p>
              </div>
            </div>

            <div>
              <label className="block text-sm mb-2 text-white/70">邮件验证码</label>
              <div className="relative">
                <input
                  type="text"
                  inputMode="numeric"
                  maxLength={6}
                  value={code}
                  onChange={e => setCode(e.target.value.replace(/\D/g, ''))}
                  className="glass-input w-full pl-10 tracking-[0.5em] text-center text-lg font-bold"
                  placeholder="6位数字验证码"
                  autoFocus
                />
                <KeyRound className="absolute left-3 top-3 w-4 h-4 text-white/40" />
              </div>
              <p className="text-xs text-white/30 mt-2">验证码 10 分钟内有效，连续输错 5 次需重新获取。</p>
            </div>

            <button
              type="submit"
              disabled={verifying}
              className="tech-button w-full flex items-center justify-center gap-2 disabled:opacity-60"
            >
              {verifying ? (
                <><Loader2 className="w-4 h-4 animate-spin" /> 验证中...</>
              ) : (
                <>下一步：修改资料 <ArrowRight size={15} /></>
              )}
            </button>

            <div className="flex items-center justify-between text-xs">
              <button
                type="button"
                onClick={backToStep1}
                className="flex items-center gap-1 text-white/40 hover:text-white/80 transition"
              >
                <ArrowLeft size={12} /> 重新填写授权信息
              </button>
              <button
                type="button"
                onClick={sendCode}
                disabled={sending || cooldown > 0}
                className="flex items-center gap-1 text-sky-300/70 hover:text-sky-300 disabled:text-white/20 disabled:cursor-not-allowed transition"
              >
                <RefreshCw size={12} className={sending ? 'animate-spin' : ''} />
                {cooldown > 0 ? `${cooldown}s 后可重发` : '重新发送验证码'}
              </button>
            </div>
          </form>
        )}

        {/* ---------------- Step 3 ---------------- */}
        {step === 3 && (
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="flex items-center gap-2 text-xs text-emerald-300 bg-emerald-500/10 border border-emerald-500/20 rounded-lg p-3">
              <ShieldCheck size={14} />
              邮箱验证通过，请填写需要修改的内容（至少修改一项）
            </div>

            <div>
              <label className="block text-sm mb-2 text-white/70">授权主人</label>
              <div className="relative">
                <input
                  type="text"
                  maxLength={50}
                  value={newOwner}
                  onChange={e => setNewOwner(e.target.value)}
                  className="glass-input w-full pl-10"
                  placeholder="请输入新的授权主人名称"
                />
                <User className="absolute left-3 top-2.5 w-4 h-4 text-white/40" />
              </div>
            </div>

            <div>
              <label className="block text-sm mb-2 text-white/70">联系邮箱</label>
              <div className="relative">
                <input
                  type="email"
                  maxLength={120}
                  value={newEmail}
                  onChange={e => setNewEmail(e.target.value)}
                  className="glass-input w-full pl-10"
                  placeholder="请输入新的联系邮箱"
                />
                <Mail className="absolute left-3 top-2.5 w-4 h-4 text-white/40" />
              </div>
              <p className="text-xs text-white/30 mt-2">留空则不修改该项；授权 QQ 与所属产品不可在此修改。</p>
            </div>

            <button
              type="submit"
              disabled={submitting}
              className="tech-button w-full flex items-center justify-center gap-2 disabled:opacity-60"
            >
              {submitting ? (
                <><Loader2 className="w-4 h-4 animate-spin" /> 提交中...</>
              ) : (
                <>确认修改 <CheckCircle2 size={15} /></>
              )}
            </button>

            <button
              type="button"
              onClick={() => setStep(2)}
              className="w-full flex items-center justify-center gap-1 text-xs text-white/40 hover:text-white/80 transition"
            >
              <ArrowLeft size={12} /> 返回验证步骤
            </button>
          </form>
        )}
      </div>

      {/* 成功结果卡片 */}
      {result && (
        <div className="glass-card p-8 mt-6 border-l-4 border-l-emerald-500 animate-fade-in-up">
          <div className="flex items-center gap-3 mb-4">
            <div className="p-2 bg-emerald-500/20 rounded-full text-emerald-400">
              <CheckCircle2 size={26} />
            </div>
            <div>
              <h3 className="text-lg font-bold text-white">授权资料已更新</h3>
              <p className="text-emerald-400/70 text-xs">验证码已失效，如需再次修改请重新验证</p>
            </div>
          </div>
          <div className="space-y-2 text-sm">
            <div className="bg-white/5 rounded-lg p-3 flex justify-between">
              <span className="text-white/50">授权主人</span>
              <span className="text-sky-300">{result.owner || '—'}</span>
            </div>
            <div className="bg-white/5 rounded-lg p-3 flex justify-between">
              <span className="text-white/50">联系邮箱</span>
              <span className="text-sky-300">{result.email || '—'}</span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
