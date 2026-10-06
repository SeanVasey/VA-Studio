import { createRoot } from 'react-dom/client';
import DesignPreview from './DesignPreview';
import '../../css/app.css';
import './preview.css';

createRoot(document.getElementById('app')!).render(<DesignPreview />);
