/** G2 reviews of TryPost, shown on the auth screens and the Welcome plan step. */
export interface Review {
    key: string;
    name: string;
    photo: string;
}

export const G2_REVIEWS_URL = 'https://www.g2.com/products/trypost/reviews';

export const REVIEWS: Review[] = [
    {
        key: 'paulo_dantas',
        name: 'Paulo Dantas',
        photo: '/images/reviews/paulo-dantas.jpg',
    },
    {
        key: 'diego',
        name: 'Diego Sampaio',
        photo: '/images/reviews/diego-sampaio.jpg',
    },
    {
        key: 'luiz',
        name: 'Luiz Mazini',
        photo: '/images/reviews/luiz-mazini.jpg',
    },
    {
        key: 'pedro',
        name: 'Pedro Campos',
        photo: '/images/reviews/pedro-campos.jpg',
    },
    {
        key: 'paulo_castellano',
        name: 'Paulo Castellano',
        photo: '/images/reviews/paulo-castellano.jpg',
    },
];
